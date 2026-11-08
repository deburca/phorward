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
CHECKS = ("lint", "phpcbf", "phpcs", "phpstan", "phpunit")
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

        # reproducibility: base images arrive as build arguments (digests from the manifest), tools come from locks
        self.assertRegex(dockerfile, r"(?m)^ARG PHP_IMAGE=")
        self.assertRegex(dockerfile, r"(?m)^ARG COMPOSER_IMAGE=")
        self.assertRegex(dockerfile, r"(?m)^FROM \$\{PHP_IMAGE\} AS php-base$")
        self.assertRegex(dockerfile, r"(?m)^FROM php-base AS locks$")
        self.assertRegex(dockerfile, r"(?m)^FROM php-base AS base$")
        self.assertIn("base-image=${PHP_IMAGE}", dockerfile, "the base image digest is recorded in /opt/TOOL-VERSIONS")
        self.assertIn("/export", dockerfile, "the locks stage leaves its output in /export")
        self.assertIn("composer install", dockerfile)
        self.assertNotRegex(dockerfile, r"composer create-project[^\n]*\n(?:[^\n]*\n){0,8}?[^\n]*composer install", "no resolution outside the locks stage")
        # A recursive chmod in a step of its own copies every file into a new layer (it once cost 450 MB); it is only free
        # in the step that creates the files.
        steps = re.sub(r"\\\n", " ", dockerfile).splitlines()
        for step in (s for s in steps if s.startswith("RUN ") and "chmod -R" in s):
            self.assertTrue("composer install" in step, f"chmod -R must share its step with the install that creates the files: {step[:80]}")

        # build section
        build = manifest["build"]
        self.assertIn(build["name_from"], NAME_FROM)
        self.assertIsInstance(build["dev_packages"], bool)
        if build["dev_packages"]:
            self.assertRegex(dockerfile, r"(?m)^ARG DEV_PACKAGES\b")

        # versions, pins and locks
        self.assertEqual(manifest["versions"]["php"], sorted(manifest["versions"]["php"]))
        self.assertIn(defaults_php := manifest["defaults"]["php"], manifest["versions"]["php"])
        self.assertIn("{php}", manifest["base"]["php_image"])
        self.assertTrue(manifest["versions"]["policy"])
        pins = manifest["base"]["pins"]
        digest = r"^docker\.io/library/[a-z]+@sha256:[0-9a-f]{64}$"
        for version in manifest["versions"]["php"]:
            self.assertRegex(pins["php"].get(version, ""), digest, f"PHP {version} base image is pinned by digest")
        self.assertRegex(pins["composer"], digest)
        self.assertTrue(manifest["locks"])
        for lock in manifest["locks"]:
            self.assertTrue((directory / "locks" / lock).is_file(), f"missing lock file {lock}")
            self.assertTrue((directory / "locks" / lock).with_name("composer.json").is_file(), f"missing requirements next to {lock}")
            self.assertIn("content-hash", (directory / "locks" / lock).read_text())

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
            if "argv_paths" in check:
                self.assertIn("{paths}", check["argv_paths"])
            if check.get("needs_paths"):
                self.assertIn("argv_paths", check, "a check that needs paths must say how to use them")
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
        gates = {n for n, c in manifest["checks"].items() if c.get("default", True)}
        self.assertEqual(covered, gates, "every check that can fail needs a must-fail case in the smoke test")
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


def profiles_copy(tmp: Path, pins: bool, locks: bool = True) -> Path:
    """A private copy of the shipped profiles, so a test can change pins and locks without touching the repository."""
    import shutil
    dest = tmp / "profiles"
    shutil.copytree(PROFILES, dest)
    for manifest_path in dest.glob("*/manifest.json"):
        manifest = json.loads(manifest_path.read_text())
        if not pins:
            manifest["base"]["pins"] = {}
        manifest_path.write_text(json.dumps(manifest, indent=2))
    if not locks:
        for lock_dir in dest.glob("*/locks"):
            shutil.rmtree(lock_dir)
    return dest


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
    PIN = "docker.io/library/php@sha256:" + "a" * 64
    COMPOSER_PIN = "docker.io/library/composer@sha256:" + "b" * 64

    def with_profiles(self, pins, locks=True):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        profiles = profiles_copy(Path(tmp.name), pins, locks)
        if pins:
            for manifest_path in profiles.glob("*/manifest.json"):
                manifest = json.loads(manifest_path.read_text())
                manifest["base"]["pins"] = {"php": {v: self.PIN for v in manifest["versions"]["php"]}, "composer": self.COMPOSER_PIN}
                manifest_path.write_text(json.dumps(manifest, indent=2))
        return profiles

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

    def test_a_pinned_base_is_used_as_it_is_and_nothing_is_pulled(self):
        profiles = self.with_profiles(pins=True)
        rc, out, err, calls = run_build("--profile", "php-library", files=self.LIB, extra_env={"PHORWARD_PROFILES_DIR": str(profiles)})
        self.assertEqual(rc, 0, err)
        _, args = build_args(calls)
        self.assertEqual(args["PHP_IMAGE"], self.PIN)
        self.assertEqual(args["COMPOSER_IMAGE"], self.COMPOSER_PIN)
        self.assertEqual([c for c in calls if c[0] == "pull"], [], "a pinned base needs no network")
        self.assertNotIn("warning", err)

    def test_an_unpinned_base_is_resolved_to_its_digest_with_a_warning(self):
        profiles = self.with_profiles(pins=False)
        rc, out, err, calls = run_build("--profile", "php-library", "--php", "8.4", files=self.LIB,
                                        extra_env={"PHORWARD_PROFILES_DIR": str(profiles)})
        self.assertEqual(rc, 0, err)
        pulls = [c for c in calls if c[0] == "pull"]
        self.assertEqual([p[-1] for p in pulls], ["docker.io/library/php:8.4-cli-trixie", "docker.io/library/composer:2"])
        _, args = build_args(calls)
        self.assertRegex(args["PHP_IMAGE"], r"^docker\.io/library/php@sha256:[0-9a-f]{64}$", "what was pulled is named by its digest")
        self.assertRegex(args["COMPOSER_IMAGE"], r"^docker\.io/library/composer@sha256:[0-9a-f]{64}$")
        self.assertIn("does not pin the PHP 8.4 base image", err)
        self.assertIn("--pin-base", err)

    def test_each_php_version_has_its_own_pin(self):
        profiles = self.with_profiles(pins=True)
        for manifest_path in profiles.glob("*/manifest.json"):
            manifest = json.loads(manifest_path.read_text())
            manifest["base"]["pins"]["php"]["8.4"] = "docker.io/library/php@sha256:" + "c" * 64
            manifest_path.write_text(json.dumps(manifest, indent=2))
        env = {"PHORWARD_PROFILES_DIR": str(profiles)}
        self.assertEqual(build_args(run_build("--profile", "php-library", "--php", "8.4", files=self.LIB, extra_env=env)[3])[1]["PHP_IMAGE"],
                         "docker.io/library/php@sha256:" + "c" * 64)
        self.assertEqual(build_args(run_build("--profile", "php-library", "--php", "8.3", files=self.LIB, extra_env=env)[3])[1]["PHP_IMAGE"], self.PIN)

    def test_a_missing_lock_file_is_refused_with_the_command_to_make_it(self):
        profiles = self.with_profiles(pins=True, locks=False)
        rc, _, err, calls = run_build("--profile", "php-library", files=self.LIB, extra_env={"PHORWARD_PROFILES_DIR": str(profiles)})
        self.assertNotEqual(rc, 0)
        self.assertIn("missing lock file", err)
        self.assertIn("build-image --refresh-locks --profile php-library", err)
        self.assertEqual([c for c in calls if c[0] == "build"], [])

    def test_no_cache_is_passed_to_the_build(self):
        profiles = self.with_profiles(pins=True)
        rc, _, err, calls = run_build("--profile", "php-library", "--no-cache", files=self.LIB, extra_env={"PHORWARD_PROFILES_DIR": str(profiles)})
        self.assertEqual(rc, 0, err)
        self.assertIn("--no-cache", next(c for c in calls if c[0] == "build"))
        rc, _, err, calls = run_build("--profile", "php-library", files=self.LIB, extra_env={"PHORWARD_PROFILES_DIR": str(profiles)})
        self.assertNotIn("--no-cache", next(c for c in calls if c[0] == "build"))

    def test_pin_base_writes_digests_for_every_supported_version_and_nothing_else(self):
        profiles = self.with_profiles(pins=False)
        manifest_path = profiles / "php-library" / "manifest.json"
        before = json.loads(manifest_path.read_text())
        rc, out, err, calls = run_build("--profile", "php-library", "--pin-base", extra_env={"PHORWARD_PROFILES_DIR": str(profiles)})
        self.assertEqual(rc, 0, err)
        after = json.loads(manifest_path.read_text())
        pins = after["base"]["pins"]
        self.assertEqual(sorted(pins["php"]), after["versions"]["php"])
        for ref in list(pins["php"].values()) + [pins["composer"]]:
            self.assertRegex(ref, r"^docker\.io/library/[a-z]+@sha256:[0-9a-f]{64}$")
        self.assertTrue(pins["php"]["8.3"] != pins["php"]["8.4"], "each version gets its own digest")
        before["base"]["pins"] = after["base"]["pins"]
        self.assertEqual(before, after, "only base.pins changes")
        self.assertEqual([c for c in calls if c[0] == "build"], [], "pinning builds nothing")
        self.assertIn("php 8.5: docker.io/library/php@sha256:", out)
        self.assertEqual(json.loads((profiles / "drupal-module" / "manifest.json").read_text())["base"]["pins"], {}, "other profiles are untouched")

    def test_refresh_locks_builds_the_locks_stage_and_copies_out_only_the_export(self):
        profiles = self.with_profiles(pins=True)
        with tempfile.TemporaryDirectory() as export:
            (Path(export) / "lint-tools").mkdir()
            (Path(export) / "lint-tools" / "composer.lock").write_text('{"content-hash": "new"}')
            (Path(export) / "lint-tools" / "composer.json").write_text("{}")
            rc, out, err, calls = run_build("--profile", "php-library", "--refresh-locks",
                                            extra_env={"PHORWARD_PROFILES_DIR": str(profiles), "FAKE_EXPORT_DIR": export})
        self.assertEqual(rc, 0, err)
        build = next(c for c in calls if c[0] == "build")
        self.assertEqual(build[build.index("--target") + 1], "locks")
        self.assertEqual([c[0] for c in calls if c[0] in ("create", "cp", "rm", "rmi")], ["create", "cp", "rm", "rmi"], "the temporary container and image are removed")
        self.assertEqual(next(c for c in calls if c[0] == "cp")[1], "fakecontainer:/export/.")
        self.assertEqual((profiles / "php-library" / "locks" / "lint-tools" / "composer.lock").read_text(), '{"content-hash": "new"}')
        self.assertIn("review the change", out)
        self.assertEqual([c for c in calls if c[0] == "run"], [], "no image of the project is built or run")

    def test_the_shipped_manifests_pin_every_version(self):
        """What build-image would use for a real build: every supported PHP version has a digest."""
        for directory in profile_dirs():
            manifest = json.loads((directory / "manifest.json").read_text())
            for version in manifest["versions"]["php"]:
                rc, out, err, calls = run_build("--profile", directory.name, "--php", version, files=self.LIB if directory.name == "php-library" else self.DRUPAL)
                self.assertEqual(rc, 0, err)
                self.assertEqual([c for c in calls if c[0] == "pull"], [], f"{directory.name} {version} is pinned")

    def test_print_name_needs_no_container_engine(self):
        """A machine without docker or podman can still ask what a project would be called."""
        import shutil
        with tempfile.TemporaryDirectory() as tmp:
            tmp = Path(tmp)
            bin_dir = tmp / "bin"
            bin_dir.mkdir()
            for tool in ("env", "bash", "dirname", "basename", "python3"):
                found = shutil.which(tool)
                self.assertIsNotNone(found, tool)
                (bin_dir / tool).symlink_to(found)
            project = tmp / "project"
            project.mkdir()
            (project / "composer.json").write_text(json.dumps({"name": "Acme/My-Lib"}))
            (project / "composer.lock").write_text("{}")
            done = subprocess.run([str(BUILD), "--profile", "php-library", "--module", str(project), "--print-name"],
                                  capture_output=True, text=True, env={"PATH": str(bin_dir)})
            self.assertEqual((done.returncode, done.stdout.strip()), (0, "my_lib"), done.stderr)
            self.assertNotIn("neither podman nor docker", done.stderr)
            # Anything that does need an engine still says so.
            done = subprocess.run([str(BUILD), "--profile", "php-library", "--module", str(project)],
                                  capture_output=True, text=True, env={"PATH": str(bin_dir)})
            self.assertEqual(done.returncode, 1)
            self.assertIn("neither podman nor docker is installed", done.stderr)

    def test_base_only_needs_no_project(self):
        rc, out, err, calls = run_build("--profile", "php-library", "--base-only")
        self.assertEqual(rc, 0, err)
        build = next(c for c in calls if c[0] == "build")
        self.assertEqual(build[build.index("--target") + 1], "base")
        self.assertIn("phorward-base-php-library:php8.3", build)


if __name__ == "__main__":
    unittest.main()
