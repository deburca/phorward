"""Tests for the profile contract (profiles/<name>/) and server/build-image.

    python3 -I -m unittest discover -s tests -v
"""
import json
import os
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PROFILES = ROOT / "profiles"
BUILD = ROOT / "server" / "build-image"
FAKE = ROOT / "tests" / "fakes" / "fake-engine"
CHECKS = ("lint", "phpcs", "phpstan", "phpunit")
NAME_FROM = ("info.yml", "composer-package")
LABELS = ("phorward.profile", "phorward.module-name", "phorward.module-path")


def profile_dirs():
    return sorted(p for p in PROFILES.iterdir() if p.is_dir())


class ProfileContractTests(unittest.TestCase):
    """Every directory under profiles/ must satisfy these, so a custom profile can be checked the same way."""

    def test_there_are_profiles(self):
        self.assertGreaterEqual(len(profile_dirs()), 2)

    def test_each_profile(self):
        for directory in profile_dirs():
            with self.subTest(profile=directory.name):
                self.check_profile(directory)

    def check_profile(self, directory):
        manifest = json.loads((directory / "manifest.json").read_text())
        dockerfile = (directory / "Dockerfile").read_text()
        self.assertEqual(manifest["profile"], directory.name)
        self.assertTrue(manifest["description"])

        # Dockerfile: the two-stage layout and the label contract
        self.assertRegex(dockerfile, r"(?m)^FROM .* AS base$")
        self.assertRegex(dockerfile, r"(?m)^FROM base AS final$")
        self.assertNotRegex(dockerfile, r"(?mi)^FROM .* AS module$", "a stage named module shadows the build context")
        for label in LABELS:
            self.assertIn(label, dockerfile)
        self.assertIn(f'phorward.profile="{directory.name}"', dockerfile)
        for arg in ("PROJECT_PACKAGE", "PROJECT_NAME", "PROJECT_REV", "LOCK_SHA256"):
            self.assertRegex(dockerfile, rf"(?m)^ARG {arg}\b")
        self.assertIn("COPY --from=module", dockerfile)
        self.assertNotRegex(dockerfile, r"(?i)hivelog")

        # build section
        build = manifest["build"]
        self.assertIn(build["name_from"], NAME_FROM)
        self.assertIsInstance(build["dev_packages"], bool)
        if build["dev_packages"]:
            self.assertRegex(dockerfile, r"(?m)^ARG DEV_PACKAGES\b")

        # run defaults
        defaults = manifest["defaults"]
        self.assertRegex(defaults["php"], r"^[0-9]+\.[0-9]+$")
        self.assertIn(defaults["db"], manifest["databases"])
        for database in manifest["databases"]:
            self.assertRegex(defaults["memory"][database], r"^[0-9]+[kmg]$", f"memory default for {database}")
        for key in ("cpus", "pids"):
            self.assertGreater(defaults[key], 0)

        # checks
        self.assertTrue(manifest["checks"])
        for name, check in manifest["checks"].items():
            self.assertIn(name, CHECKS)
            self.assertTrue(check["argv"] and all(isinstance(a, str) for a in check["argv"]))
            self.assertTrue(check["workdir"].startswith(("/", "{module_path}")))
            if "argv_tests" in check:
                self.assertIn("{tests}", check["argv_tests"])
        self.assertEqual(set(manifest["provides"]), set(manifest["checks"]))
        for database, spec in manifest["databases"].items():
            for wrapper in spec.get("wrap", []):
                self.assertIn(wrapper, dockerfile, f"{database} wrapper {wrapper} is not installed by the Dockerfile")

        # smoke section: every template exists and every check it names is one the profile has
        smoke = manifest["smoke"]
        self.assertTrue(smoke["pick_tests"])
        self.assertTrue(set(smoke["good_checks"]) <= set(manifest["checks"]))
        templates = [spec["template"] for spec in smoke["bad"].values()]
        for check, kinds in smoke["scratch"].items():
            self.assertIn(check, manifest["checks"])
            for files in kinds.values():
                templates += list(files.values())
        for check in smoke["bad"]:
            self.assertIn(check, manifest["checks"])
        for template in templates:
            self.assertTrue((directory / template).is_file(), f"missing smoke template {template}")
        covered = set(smoke["bad"]) | set(smoke["scratch"])
        self.assertEqual(covered, set(manifest["checks"]), "every check needs a must-fail case in the smoke test")
        self.assertIn("phpunit", smoke["bad"])
        self.assertTrue(smoke["bad"]["phpunit"]["tests"])
        self.assertIn(manifest["test_layout"], ("drupal", "flat"))

    def test_helper_scripts_named_by_manifests_are_in_the_profile(self):
        for directory in profile_dirs():
            manifest = json.loads((directory / "manifest.json").read_text())
            dockerfile = (directory / "Dockerfile").read_text()
            for check in manifest["checks"].values():
                for word in check["argv"]:
                    if re.fullmatch(r"(with|phorward)-[a-z-]+", word):
                        self.assertIn(word, dockerfile, f"{directory.name}: {word} is not installed")
                        self.assertTrue((directory / word).is_file(), f"{directory.name}: {word} not in profile")


def run_build(*args, files=None, extra_env=None):
    """Run build-image against the fake engine in a scratch project; returns (rc, stdout, stderr, calls)."""
    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)
        project = tmp / "project"
        project.mkdir()
        for name, content in (files or {}).items():
            (project / name).parent.mkdir(parents=True, exist_ok=True)
            (project / name).write_text(content)
        state = tmp / "state"
        state.mkdir()
        env = {"PATH": os.environ["PATH"], "PHORWARD_ENGINE": str(FAKE), "FAKE_STATE": str(state)}
        env.update(extra_env or {})
        done = subprocess.run([str(BUILD), "--module", str(project), *args], capture_output=True, text=True, env=env)
        calls = []
        log = state / "calls.jsonl"
        if log.exists():
            calls = [json.loads(line) for line in log.read_text().splitlines()]
        return done.returncode, done.stdout, done.stderr, calls


def build_args(calls):
    build = next(c for c in calls if c[0] == "build")
    pairs = {}
    for i, a in enumerate(build):
        if a == "--build-arg":
            k, _, v = build[i + 1].partition("=")
            pairs[k] = v
    return build, pairs


class BuildImageTests(unittest.TestCase):
    DRUPAL = {"composer.json": json.dumps({"name": "acme/thing"}), "thing.info.yml": "name: Thing\n"}
    LIB = {"composer.json": json.dumps({"name": "Acme/My-Lib"}), "composer.lock": "{}"}

    def test_lists_profiles(self):
        done = subprocess.run([str(BUILD), "--list-profiles"], capture_output=True, text=True)
        self.assertEqual(done.returncode, 0)
        for directory in profile_dirs():
            self.assertIn(directory.name, done.stdout)

    def test_drupal_names_the_project_from_the_info_file(self):
        rc, out, err, calls = run_build("--profile", "drupal-module", "--print-name", files=self.DRUPAL)
        self.assertEqual((rc, out.strip()), (0, "thing"), err)

    def test_library_names_the_project_from_the_package(self):
        rc, out, err, calls = run_build("--profile", "php-library", "--print-name", files=self.LIB)
        self.assertEqual((rc, out.strip()), (0, "my_lib"), err)

    def test_name_override_is_validated(self):
        rc, _, err, _ = run_build("--profile", "php-library", "--name", "Bad-Name", files=self.LIB)
        self.assertNotEqual(rc, 0)
        self.assertIn("lowercase", err)

    def test_build_passes_project_arguments_and_tags(self):
        rc, out, err, calls = run_build("--profile", "drupal-module", "--php", "8.5", "--dev-package", "drupal/key",
                                        "--dev-package", "drupal/simple_oauth", files=self.DRUPAL)
        self.assertEqual(rc, 0, err)
        build, args = build_args(calls)
        self.assertEqual(args["PROJECT_PACKAGE"], "acme/thing")
        self.assertEqual(args["PROJECT_NAME"], "thing")
        self.assertEqual(args["PHP_VERSION"], "8.5")
        self.assertEqual(args["DEV_PACKAGES"], "drupal/key drupal/simple_oauth")
        self.assertRegex(args["LOCK_SHA256"], r"^[0-9a-f]{64}$", "the module's composer.json is hashed")
        self.assertIn("phorward:thing-php8.5", build)
        self.assertTrue(any(re.fullmatch(r"phorward:thing-php8\.5-\d{8}", a) for a in build))
        self.assertIn("--target", build)
        self.assertEqual(build[build.index("--target") + 1], "final")
        self.assertTrue(any(a.startswith("module=") for a in build), "the project is the `module` build context")

    def test_default_php_comes_from_the_manifest(self):
        rc, _, err, calls = run_build("--profile", "php-library", files=self.LIB)
        self.assertEqual(rc, 0, err)
        self.assertEqual(build_args(calls)[1]["PHP_VERSION"], "8.3")

    def test_library_hashes_the_lock_file(self):
        rc, _, err, calls = run_build("--profile", "php-library", files={**self.LIB, "composer.lock": "locked"})
        self.assertEqual(rc, 0, err)
        import hashlib
        self.assertEqual(build_args(calls)[1]["LOCK_SHA256"], hashlib.sha256(b"locked").hexdigest())

    def test_library_needs_a_lock_file(self):
        rc, _, err, calls = run_build("--profile", "php-library", files={"composer.json": json.dumps({"name": "a/b"})})
        self.assertNotEqual(rc, 0)
        self.assertIn("composer.lock", err)
        self.assertEqual([c for c in calls if c[0] == "build"], [])

    def test_library_refuses_dev_packages(self):
        rc, _, err, calls = run_build("--profile", "php-library", "--dev-package", "x/y", files=self.LIB)
        self.assertEqual(rc, 2)
        self.assertEqual([c for c in calls if c[0] == "build"], [])

    def test_unknown_profile(self):
        rc, _, err, _ = run_build("--profile", "nope", files=self.DRUPAL)
        self.assertEqual(rc, 2)
        self.assertIn("unknown profile", err)

    def test_drupal_needs_exactly_one_info_file(self):
        rc, _, err, _ = run_build("--profile", "drupal-module", files={"composer.json": json.dumps({"name": "a/b"})})
        self.assertNotEqual(rc, 0)
        self.assertIn("info.yml", err)

    def test_base_only_needs_no_project(self):
        rc, out, err, calls = run_build("--profile", "php-library", "--base-only")
        self.assertEqual(rc, 0, err)
        build = next(c for c in calls if c[0] == "build")
        self.assertEqual(build[build.index("--target") + 1], "base")
        self.assertIn("phorward-base-php-library:php8.3", build)


if __name__ == "__main__":
    unittest.main()
