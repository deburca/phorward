"""Tests for server/images (list and prune), against the fake engine."""
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TOOL = ROOT / "server" / "images"
FAKE = ROOT / "tests" / "fakes" / "fake-engine"


def image(tag, built="2026-11-05", size=2_400_000_000, profile="drupal-module", lock="a1b2c3d4e5f6a7b8"):
    return {"tag": tag, "profile": profile, "created": built + "T12:00:00Z", "size": size, "id": "sha256:" + tag[-8:].ljust(16, "0"), "lock": lock}


class ImagesTests(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self._tmp.cleanup)
        self.state = Path(self._tmp.name)

    def tool(self, table, *args):
        env = {"PATH": os.environ["PATH"], "PHORWARD_ENGINE": str(FAKE), "FAKE_STATE": str(self.state), "FAKE_IMAGES_JSON": json.dumps(table)}
        done = subprocess.run([sys.executable, "-I", str(TOOL), *args], capture_output=True, text=True, env=env)
        return done.returncode, done.stdout, done.stderr

    def removed(self):
        log = self.state / "calls.jsonl"
        calls = [json.loads(l) for l in log.read_text().splitlines()] if log.exists() else []
        return [c[1] for c in calls if c[0] == "rmi"]

    def history(self):
        return [
            image("phorward:thing-php8.3", built="2026-11-07"),
            image("phorward:thing-php8.3-20261107", built="2026-11-07"),
            image("phorward:thing-php8.3-20261106", built="2026-11-06"),
            image("phorward:thing-php8.3-20261105", built="2026-11-05"),
            image("phorward:thing-php8.3-20261104", built="2026-11-04"),
            image("phorward:thing-php8.3-20261101", built="2026-11-01"),
            image("phorward:thing-php8.5-20261030", built="2026-10-30"),
            image("phorward:other-php8.3", built="2026-11-07", profile="php-library", size=900_000_000),
        ]

    def test_list_groups_a_name_and_php_version_on_one_line(self):
        rc, out, err = self.tool(self.history(), "list")
        self.assertEqual(rc, 0, err)
        lines = out.splitlines()
        thing = next(l for l in lines if l.startswith("phorward:thing-php8.3 "))
        self.assertIn("2026-11-07", thing)
        self.assertIn("2.4 GB", thing)
        self.assertIn("20261107, 20261106, 20261105, 20261104, 20261101", thing, "dated tags, newest first")
        self.assertIn("a1b2c3d4e5f6", thing, "the dependency hash recorded in the image")
        other = next(l for l in lines if l.startswith("phorward:other-php8.3 "))
        self.assertIn("php-library", other)
        self.assertIn("900.0 MB", other)
        self.assertTrue(any("thing-php8.5" in l and "no moving tag" in l for l in lines), "a name with only a dated tag is flagged")

    def test_list_can_be_filtered_by_profile(self):
        rc, out, err = self.tool(self.history(), "list", "--profile", "php-library")
        self.assertIn("phorward:other-php8.3", out)
        self.assertNotIn("thing", out)

    def test_list_ignores_images_that_are_not_phorward_images(self):
        table = self.history() + [image("phorward:thing-php8.3-20261010", profile=""), image("nginx:latest"), image("phorward-base-drupal-module:php8.3")]
        rc, out, err = self.tool(table, "list")
        self.assertNotIn("nginx", out)
        self.assertNotIn("20261010", out)
        self.assertNotIn("phorward-base", out)

    def test_prune_only_prints_unless_applied(self):
        rc, out, err = self.tool(self.history(), "prune")
        self.assertEqual(rc, 0, err)
        self.assertIn("would remove phorward:thing-php8.3-20261104", out)
        self.assertIn("would remove phorward:thing-php8.3-20261101", out)
        self.assertIn("nothing was removed; run again with --apply", out)
        self.assertEqual(self.removed(), [])

    def test_prune_keeps_the_newest_three_dated_tags_and_every_moving_tag(self):
        rc, out, err = self.tool(self.history(), "prune", "--apply")
        self.assertEqual(rc, 0, err)
        self.assertEqual(sorted(self.removed()), ["phorward:thing-php8.3-20261101", "phorward:thing-php8.3-20261104"])

    def test_keep_is_per_name_and_php_version(self):
        rc, out, err = self.tool(self.history(), "prune", "--keep", "1", "--apply")
        self.assertEqual(sorted(self.removed()), ["phorward:thing-php8.3-20261101", "phorward:thing-php8.3-20261104",
                                                  "phorward:thing-php8.3-20261105", "phorward:thing-php8.3-20261106"])

    def test_keep_zero_removes_every_dated_tag_but_no_moving_tag(self):
        rc, out, err = self.tool(self.history(), "prune", "--keep", "0", "--apply")
        removed = self.removed()
        self.assertEqual(len(removed), 6)
        self.assertTrue(all(__import__("re").search(r"-\d{8}$", t) for t in removed))

    def test_nothing_to_prune(self):
        rc, out, err = self.tool(self.history()[:3], "prune")
        self.assertEqual(rc, 0)
        self.assertIn("nothing to prune", out)

    def test_negative_keep_is_refused(self):
        rc, out, err = self.tool(self.history(), "prune", "--keep", "-1")
        self.assertNotEqual(rc, 0)
        self.assertEqual(self.removed(), [])

    def test_a_dated_tag_of_a_non_phorward_image_is_never_touched(self):
        table = self.history() + [image("phorward:thing-php8.3-20200101", profile="")]
        rc, out, err = self.tool(table, "prune", "--keep", "0", "--apply")
        self.assertNotIn("phorward:thing-php8.3-20200101", self.removed())


if __name__ == "__main__":
    unittest.main()
