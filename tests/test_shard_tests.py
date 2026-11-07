"""Tests for server/shard-tests.py, run with: python3 -I -m unittest discover -s tests -v"""
import contextlib
import importlib.util
import io
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parent.parent / "server" / "shard-tests.py"
spec = importlib.util.spec_from_file_location("shard_tests", SCRIPT)
shard_tests = importlib.util.module_from_spec(spec)
spec.loader.exec_module(shard_tests)

TEST_CLASS = "<?php\nclass {name} {{\n{methods}}}\n"


def php_test(methods: int, extra: str = "") -> str:
    body = "".join(f"  public function testCase{i}() {{}}\n" for i in range(methods))
    return TEST_CLASS.format(name="T", methods=extra + body)


class TreeHelpers(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self._tmp.cleanup)
        self.root = Path(self._tmp.name)

    def write(self, rel: str, text: str) -> Path:
        path = self.root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)
        return path

    def run_main(self, *args):
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            shard_tests.main(["shard-tests.py", *args])
        return out.getvalue()


class ShardTestsCase(TreeHelpers):
    def build_tree(self) -> list[Path]:
        """A module with Kernel and Unit tests, plus a submodule's tests, of uneven sizes."""
        made = []
        for i in range(1, 8):
            made.append(self.write(f"tests/src/Kernel/K{i}Test.php", php_test(i)))
        for i in range(1, 4):
            made.append(self.write(f"tests/src/Unit/U{i}Test.php", php_test(i)))
        made.append(self.write("modules/sub/tests/src/Kernel/SubTest.php", php_test(4)))
        made.append(self.write("modules/sub/tests/src/Unit/Nested/DeepTest.php", php_test(2)))
        return sorted(made)

    def test_finds_kernel_and_unit_tests_of_module_and_submodules(self):
        expected = self.build_tree()
        self.assertEqual(sorted(shard_tests.all_tests(self.root)), expected)

    def test_each_file_lands_in_exactly_one_shard_for_1_to_8_shards(self):
        expected = self.build_tree()
        files = shard_tests.all_tests(self.root)
        for count in range(1, 9):
            with self.subTest(shards=count):
                bins = shard_tests.shards(files, count, self.root)
                self.assertEqual(len(bins), count)
                dealt = [p for b in bins for p in b[2]]
                self.assertEqual(len(dealt), len(set(dealt)), "a file was repeated")
                self.assertEqual(sorted(dealt), expected)

    def test_shards_are_deterministic(self):
        self.build_tree()
        runs = {self.run_main(str(self.root), "3/4") for _ in range(5)}
        self.assertEqual(len(runs), 1)

    def test_printed_shards_partition_the_tests(self):
        expected = {str(p) for p in self.build_tree()}
        seen = []
        for i in range(1, 4):
            seen += self.run_main(str(self.root), f"{i}/3").split()
        self.assertEqual(sorted(seen), sorted(expected))

    def test_shards_are_balanced(self):
        self.build_tree()
        files = shard_tests.all_tests(self.root)
        totals = [b[0] for b in shard_tests.shards(files, 3, self.root)]
        heaviest_file = max(shard_tests.cost(p, self.root) for p in files)
        self.assertLessEqual(max(totals) - min(totals), heaviest_file)

    def test_stray_test_file_is_rejected(self):
        self.build_tree()
        self.write("tests/src/Kernel/Misnamed.php", php_test(1))
        with self.assertRaises(SystemExit) as caught:
            shard_tests.all_tests(self.root)
        self.assertIn("Misnamed.php", str(caught.exception))

    def test_helper_files_without_tests_are_ignored(self):
        self.build_tree()
        self.write("tests/src/Kernel/KernelTestBase.php", "<?php\nabstract class B {}\n")
        self.assertEqual(len(shard_tests.all_tests(self.root)), 12)

    def test_skipped_directories_are_not_searched(self):
        self.write("tests/src/Unit/RealTest.php", php_test(1))
        self.write("vendor/x/tests/src/Unit/VendorTest.php", php_test(1))
        self.write("docs/tests/src/Unit/DocTest.php", php_test(1))
        names = [p.name for p in shard_tests.all_tests(self.root)]
        self.assertEqual(names, ["RealTest.php"])

    def test_no_tests_is_an_error(self):
        with self.assertRaises(SystemExit):
            self.run_main(str(self.root), "1/1")

    def test_check_mode_passes(self):
        self.build_tree()
        self.assertIn("ok: 12 test files", self.run_main(str(self.root), "--check"))

    def test_bad_shard_arguments_are_rejected(self):
        self.build_tree()
        for arg in ("0/3", "4/3"):
            with self.subTest(arg=arg), self.assertRaises(SystemExit):
                self.run_main(str(self.root), arg)
        with self.assertRaises(SystemExit):
            shard_tests.main(["shard-tests.py"])

    def test_cost_estimate(self):
        kernel = self.write("tests/src/Kernel/ATest.php", php_test(3))
        separate = self.write("tests/src/Kernel/BTest.php",
                              php_test(3, "  // @RunTestsInSeparateProcesses\n"))
        unit = self.write("tests/src/Unit/CTest.php", php_test(3))
        base = 3 * shard_tests.SECONDS_PER_TEST
        self.assertAlmostEqual(shard_tests.cost(kernel, self.root), base)
        self.assertAlmostEqual(shard_tests.cost(separate, self.root), base * 2)
        self.assertAlmostEqual(shard_tests.cost(unit, self.root), base * 0.05)

    def test_measured_time_overrides_estimate(self):
        path = self.write("tests/src/Kernel/ATest.php", php_test(1))
        original = dict(shard_tests.TIMES)
        self.addCleanup(lambda: (shard_tests.TIMES.clear(), shard_tests.TIMES.update(original)))
        shard_tests.TIMES["tests/src/Kernel/ATest.php"] = 42.0
        self.assertEqual(shard_tests.cost(path, self.root), 42.0)


class FlatLayoutCase(TreeHelpers):
    """The `flat` layout, for plain PHP libraries: every `tests` directory counts, at any depth below it."""

    def flat_tree(self):
        made = []
        for i in range(1, 6):
            made.append(self.write(f"tests/Thing{i}Test.php", php_test(i)))
        made.append(self.write("tests/Sub/Deep/OtherTest.php", php_test(2)))
        made.append(self.write("packages/a/tests/ATest.php", php_test(1)))
        return sorted(made)

    def test_finds_every_tests_directory(self):
        expected = self.flat_tree()
        self.assertEqual(sorted(shard_tests.all_tests(self.root, "flat")), expected)

    def test_drupal_layout_ignores_a_flat_tree(self):
        self.flat_tree()
        self.assertEqual(shard_tests.all_tests(self.root, "drupal"), [])

    def test_each_file_lands_in_exactly_one_shard(self):
        expected = self.flat_tree()
        files = shard_tests.all_tests(self.root, "flat")
        for count in range(1, 9):
            with self.subTest(shards=count):
                dealt = [p for b in shard_tests.shards(files, count, self.root) for p in b[2]]
                self.assertEqual(sorted(dealt), expected)

    def test_vendor_and_stray_files(self):
        self.write("vendor/x/tests/XTest.php", php_test(1))
        self.write("tests/OneTest.php", php_test(1))
        self.assertEqual([p.name for p in shard_tests.all_tests(self.root, "flat")], ["OneTest.php"])
        self.write("tests/Misnamed.php", php_test(1))
        with self.assertRaises(SystemExit):
            shard_tests.all_tests(self.root, "flat")

    def test_command_line_layout_option(self):
        self.flat_tree()
        self.assertIn("ok: 7 test files", self.run_main(str(self.root), "--check", "--layout", "flat"))
        printed = self.run_main(str(self.root), "1/1", "--layout", "flat").split()
        self.assertEqual(len(printed), 7)
        with self.assertRaises(SystemExit):
            self.run_main(str(self.root), "1/1", "--layout", "nope")


if __name__ == "__main__":
    unittest.main()
