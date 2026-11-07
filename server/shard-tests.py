#!/usr/bin/env python3
"""Splits a module's Kernel and Unit tests into shards that run in parallel.

    shard-tests.py <module root> <i>/<n> [--layout drupal|flat]   # prints shard i of n: test files, space separated
    shard-tests.py <module root> --check [--layout drupal|flat]   # proves that no shard count from 1 to 8 loses a test

Tests are found by walking the root for test directories, so a new one joins a shard on its own and a
fixed list can never silently skip tests. The layout says which directories count:
  drupal (default)  every `*/tests/src/Kernel` and `*/tests/src/Unit` directory (a Drupal module's own and
                    each submodule's)
  flat              every directory named `tests` (a plain PHP library's)
A file is a `*Test.php`; any other file in them that declares tests is an error, because PHPUnit's default
suffix would never run it.

Shards are balanced, not alphabetical: each file gets a cost in seconds, then the files are dealt
out largest first, each to the shard with the least so far. The result is deterministic, so a failed
shard can be re-run and a local run matches. The cost is the file's measured time from an optional
test-times.json next to this script (relative path -> seconds), or, for a file with no measurement,
an estimate: 1.3 s a test method, doubled for a class that runs every test in a separate process, and
nearly nothing for a unit test, which needs no Drupal.
"""
import json
import os
import re
import sys
from pathlib import Path

SKIP_DIRS = {".git", "vendor", "node_modules", "demo", "docs"}
KINDS = ("Kernel", "Unit")
SECONDS_PER_TEST = 1.3
OVERHEAD = {}   # shard index -> seconds already spent there (e.g. extra checks run in shard 1)
TIMES_FILE = Path(__file__).resolve().parent / "test-times.json"
TIMES = json.loads(TIMES_FILE.read_text()) if TIMES_FILE.exists() else {}


LAYOUTS = ("drupal", "flat")


def test_dirs(root: Path, layout: str = "drupal"):
    for here, dirs, _ in os.walk(root):
        dirs[:] = sorted(d for d in dirs if d not in SKIP_DIRS)
        path = Path(here)
        if layout == "flat":
            if path.name == "tests" and path != root:
                yield path
                dirs[:] = []   # its subdirectories belong to it
        elif path.parts[-3:-1] == ("tests", "src") and path.name in KINDS:
            yield path


def cost(path: Path, root: Path) -> float:
    measured = TIMES.get(path.relative_to(root).as_posix())
    if measured is not None:
        return measured
    text = path.read_text()
    methods = len(re.findall(r"public function test\w+", text))
    weight = max(methods, 1) * SECONDS_PER_TEST
    if "RunTestsInSeparateProcesses" in text:
        weight *= 2
    if "/Unit/" in str(path):
        weight *= 0.05
    return weight


def all_tests(root: Path, layout: str = "drupal"):
    files, stray = [], []
    for directory in test_dirs(root, layout):
        for path in sorted(directory.rglob("*.php")):
            if path.name.endswith("Test.php"):
                files.append(path)
            elif re.search(r"public function test\w+", path.read_text()):
                stray.append(path)
    if stray:
        sys.exit("Tests in files PHPUnit will not run (name must end in Test.php): "
                 + ", ".join(str(p) for p in stray))
    return files


def shards(files, count, root):
    bins = [[OVERHEAD.get(i, 0.0), i, []] for i in range(count)]
    costs = {p: cost(p, root) for p in files}
    for path in sorted(files, key=lambda p: (-costs[p], str(p))):
        lightest = min(bins, key=lambda b: (b[0], b[1]))
        lightest[0] += costs[path]
        lightest[2].append(path)
    return bins


def main(argv):
    layout = "drupal"
    if len(argv) == 5 and argv[3] == "--layout":
        layout, argv = argv[4], argv[:3]
    if len(argv) != 3 or layout not in LAYOUTS:
        sys.exit(__doc__)
    root = Path(argv[1])
    files = all_tests(root, layout)
    if not files:
        sys.exit(f"No tests found under {root}")
    if argv[2] == "--check":
        for count in range(1, 9):
            dealt = [p for b in shards(files, count, root) for p in b[2]]
            assert sorted(dealt) == sorted(files), f"{count} shards lose or repeat a test file"
        print(f"ok: {len(files)} test files, none lost or repeated for 1 to 8 shards")
        return
    index, count = (int(x) for x in argv[2].split("/"))
    if not 1 <= index <= count:
        sys.exit(f"shard {argv[2]}: need 1 <= i <= n")
    print(" ".join(str(p) for p in sorted(shards(files, count, root)[index - 1][2])))


if __name__ == "__main__":
    main(sys.argv)
