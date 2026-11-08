"""Tests for templates/harness/harness.sh: the retry loop around opencode and `phorward verify`.

opencode and phorward are replaced by small scripts that record how they were called and answer from a script, so
the loop itself is what is under test: how many sessions, what each is told, and what counts as a failed attempt.
"""
import os
import shutil
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

HARNESS = Path(__file__).resolve().parent.parent / "templates" / "harness" / "harness.sh"
HAS_TIMEOUT = shutil.which("timeout") or shutil.which("gtimeout")

FAKE_OPENCODE = """#!/bin/sh
# records the call; behaviour comes from $FAKE/opencode-N (a shell snippet run in the work tree), if present
n=$(($(cat "$FAKE/oc-count" 2>/dev/null || echo 0) + 1)); echo $n > "$FAKE/oc-count"
printf '%s\\n' "$*" > "$FAKE/oc-$n.args"
[ -f "$FAKE/opencode-$n" ] && . "$FAKE/opencode-$n"
exit 0
"""

FAKE_PHORWARD = """#!/bin/sh
# records the call; the exit status and report of call N come from $FAKE/verify-N.rc and verify-N.txt
n=$(($(cat "$FAKE/pw-count" 2>/dev/null || echo 0) + 1)); echo $n > "$FAKE/pw-count"
printf '%s\\n' "$*" > "$FAKE/pw-$n.args"
[ -f "$FAKE/verify-$n.txt" ] && cat "$FAKE/verify-$n.txt" || echo "report $n"
exit "$(cat "$FAKE/verify-$n.rc" 2>/dev/null || echo 1)"
"""


class HarnessTest(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp(prefix="phorward-harness-"))
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.fake = self.tmp / "fake"
        self.fake.mkdir()
        self.repo = self.tmp / "repo"
        self.repo.mkdir()
        self.git("init", "-q", "-b", "main")
        (self.repo / "README.md").write_text("x\n")
        self.git("add", "-A")
        self.git("commit", "-q", "-m", "first")
        self.script("bin/opencode", FAKE_OPENCODE)
        self.script("bin/phorward", FAKE_PHORWARD)

    def script(self, name, body):
        path = self.tmp / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(body)
        path.chmod(0o755)

    def git(self, *args):
        subprocess.run(["git", "-c", "user.name=T", "-c", "user.email=t@example.invalid", "-C", str(self.repo), *args],
                       check=True, capture_output=True)

    def verdicts(self, *rcs):
        for i, rc in enumerate(rcs, 1):
            (self.fake / f"verify-{i}.rc").write_text(str(rc))

    def opencode_does(self, n, snippet):
        (self.fake / f"opencode-{n}").write_text(textwrap.dedent(snippet))

    def harness(self, *args, env=None, timeout=60):
        environment = dict(os.environ, FAKE=str(self.fake), PATH=f"{self.tmp / 'bin'}:{os.environ['PATH']}",
                           HARNESS_PHORWARD=str(self.tmp / "bin" / "phorward"), HARNESS_AGENT_RETRY_WAIT="0")
        environment.update(env or {})
        return subprocess.run(["bash", str(HARNESS), *args], cwd=self.repo, env=environment, capture_output=True, text=True,
                              timeout=timeout)

    def count(self, name):
        path = self.fake / name
        return int(path.read_text()) if path.exists() else 0

    def args(self, prefix, n):
        return (self.fake / f"{prefix}-{n}.args").read_text()

    def test_a_model_is_required(self):
        done = self.harness("fix it")
        self.assertEqual(done.returncode, 2)
        self.assertIn("no model", done.stderr)
        self.assertEqual(self.count("oc-count"), 0)

    def test_the_command_written_by_the_installer_is_used_when_the_environment_names_none(self):
        (self.repo / ".harness").mkdir()
        (self.repo / ".harness" / "command").write_text(f"{self.tmp / 'bin' / 'phorward'}\n")
        self.verdicts(0)
        done = self.harness("-m", "p/m", "fix the thing", env={"HARNESS_PHORWARD": ""})
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertEqual(self.count("pw-count"), 1)

    def test_style_is_fixed_by_default_and_not_when_switched_off(self):
        self.verdicts(0)
        self.harness("-m", "p/m", "fix the thing")
        self.assertIn("--fix", self.args("pw", 1).split())
        (self.fake / "pw-count").write_text("0")
        self.verdicts(0)
        self.harness("-m", "p/m", "fix the thing", env={"HARNESS_FIX": "0"})
        self.assertNotIn("--fix", self.args("pw", 1).split())

    def test_passing_on_the_first_attempt(self):
        self.verdicts(0)
        done = self.harness("-m", "p/m", "fix the thing")
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertEqual(self.count("oc-count"), 1)
        self.assertIn("fix the thing", self.args("oc", 1))
        self.assertIn("--agent phorward-dev --model p/m", self.args("oc", 1))
        self.assertEqual(self.args("pw", 1).split(), ["verify", "--fix"])

    def test_each_failed_attempt_is_a_new_session_that_sees_the_last_report(self):
        self.verdicts(1, 1, 0)
        (self.fake / "verify-1.txt").write_text("phpcs FAILED: missing doc comment\n")
        (self.fake / "verify-2.txt").write_text("phpstan FAILED: undefined variable\n")
        done = self.harness("-m", "p/m", "fix the thing")
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertEqual(self.count("oc-count"), 3)
        self.assertNotIn("missing doc comment", self.args("oc", 1))
        self.assertIn("missing doc comment", self.args("oc", 2))
        self.assertIn("undefined variable", self.args("oc", 3))
        self.assertNotIn("missing doc comment", self.args("oc", 3))

    def test_the_attempts_are_bounded_and_the_last_report_is_kept(self):
        self.verdicts(1, 1, 1, 1)
        done = self.harness("-m", "p/m", "-n", "2", "fix the thing")
        self.assertEqual(done.returncode, 1)
        self.assertEqual(self.count("oc-count"), 2)
        self.assertIn("report 2", (self.repo / ".harness" / "last-report.txt").read_text())

    def test_one_last_attempt_with_the_escalation_model(self):
        self.verdicts(1, 1, 0)
        done = self.harness("-m", "p/small", "-e", "p/big", "-n", "2", "fix it")
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertIn("--model p/small", self.args("oc", 2))
        self.assertIn("--model p/big", self.args("oc", 3))

    def test_a_gate_that_cannot_run_is_not_an_attempt(self):
        for rc in (2, 70, 75):
            shutil.rmtree(self.fake)
            self.fake.mkdir()
            self.verdicts(rc)
            done = self.harness("-m", "p/m", "-n", "3", "fix it")
            self.assertEqual(done.returncode, 3, f"phorward exit {rc}: " + done.stdout)
            self.assertEqual(self.count("oc-count"), 1, "no further model session is spent when the gates are down")
            self.assertIn("not counted as an attempt", done.stdout)

    def test_the_base_is_passed_on(self):
        self.verdicts(0)
        self.harness("-m", "p/m", "--base", "origin/main", "fix it")
        self.assertEqual(self.args("pw", 1).split(), ["verify", "--base", "origin/main", "--fix"])

    def test_dry_run_runs_nothing(self):
        done = self.harness("-m", "p/m", "--dry-run", "fix the thing")
        self.assertEqual(done.returncode, 1)
        self.assertIn("fix the thing", done.stdout)
        self.assertEqual(self.count("oc-count"), 0)
        self.assertEqual(self.count("pw-count"), 0)

    def test_agent_not_found_is_retried_once(self):
        self.script("bin/opencode", """#!/bin/sh
n=$(($(cat "$FAKE/oc-count" 2>/dev/null || echo 0) + 1)); echo $n > "$FAKE/oc-count"
[ "$n" -eq 1 ] && { echo "Agent not found: phorward-dev"; exit 1; }
exit 0
""")
        self.verdicts(0)
        done = self.harness("-m", "p/m", "fix it")
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertEqual(self.count("oc-count"), 2)

    @unittest.skipUnless(HAS_TIMEOUT, "needs timeout or gtimeout")
    def test_a_session_that_hangs_is_cut_off(self):
        self.script("bin/opencode", """#!/bin/sh
n=$(($(cat "$FAKE/oc-count" 2>/dev/null || echo 0) + 1)); echo $n > "$FAKE/oc-count"
exec sleep 30
""")
        self.verdicts(0)
        done = self.harness("-m", "p/m", "fix it", env={"HARNESS_SESSION_TIMEOUT": "1"}, timeout=20)
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertEqual(self.count("oc-count"), 1)

    def test_test_first_writes_a_spec_that_fix_sessions_may_not_edit(self):
        self.opencode_does(1, """
            mkdir -p tests/src/Unit
            echo '<?php // spec' > tests/src/Unit/SpecTest.php
        """)
        self.opencode_does(2, """
            echo '<?php // weakened' > tests/src/Unit/SpecTest.php
        """)
        # call 1: syntax,phpcs lint ok; call 2: phpunit red (tests ran and failed); call 3: the gate after the fix attempt
        (self.fake / "verify-2.txt").write_text("There was 1 failure:\nFAILURES!\nTests: 1, Assertions: 1, Failures: 1.\n")
        self.verdicts(0, 1, 0)
        done = self.harness("-m", "p/m", "--test-first", "fix it")
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertIn("--agent phorward-tests", self.args("oc", 1))
        self.assertEqual(self.args("pw", 1).split(), ["verify", "--fix", "--only", "syntax,phpcs"])
        self.assertEqual(self.args("pw", 2).split(), ["verify", "--fix", "--only", "phpunit", "--tests", "tests/src/Unit/SpecTest.php"])
        self.assertIn("specification tests", self.args("oc", 2))
        self.assertIn("SpecTest.php", self.args("oc", 2))
        self.assertEqual((self.repo / "tests/src/Unit/SpecTest.php").read_text(), "<?php // spec\n")
        self.assertIn("restored spec test", done.stdout)

    def test_a_spec_that_does_not_even_run_is_rejected_and_the_writer_told_why(self):
        self.opencode_does(1, "mkdir -p tests; echo '<?php' > tests/BrokenTest.php")
        self.opencode_does(2, "mkdir -p tests; echo '<?php // fixed' > tests/BrokenTest.php")
        (self.fake / "verify-2.txt").write_text("PHP Fatal error: Cannot declare class X\n")
        self.verdicts(0, 1, 0, 1, 0)
        done = self.harness("-m", "p/m", "--test-first", "fix it")
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertIn("spec rejected", done.stdout)
        self.assertIn("The tests do not run", self.args("oc", 2))

    def test_gates_down_during_the_spec_phase_stops_without_blaming_the_model(self):
        self.opencode_does(1, "mkdir -p tests; echo '<?php' > tests/ATest.php")
        self.verdicts(70)
        done = self.harness("-m", "p/m", "--test-first", "fix it")
        self.assertEqual(done.returncode, 3, done.stdout)
        self.assertEqual(self.count("oc-count"), 1)


if __name__ == "__main__":
    unittest.main()
