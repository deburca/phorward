"""Tests for server/phorward-run against tests/fakes/fake-engine (no container engine needed).

    python3 -I -m unittest discover -s tests -v
"""
import fcntl
import io
import json
import os
import signal
import subprocess
import sys
import tarfile
import tempfile
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
WRAPPER = ROOT / "server" / "phorward-run"
FAKE = ROOT / "tests" / "fakes" / "fake-engine"
MODULE_PATH = "/opt/drupal-project/web/modules/contrib/smoke"


def make_tar(members=None):
    """members: {name: bytes | ("sym", target) | ("hard", target) | ("dev", None) | ("dir", None)}"""
    members = members if members is not None else {"smoke.info.yml": b"name: smoke\n", "src/A.php": b"<?php\n"}
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w", format=tarfile.PAX_FORMAT) as tar:
        for name, content in members.items():
            info = tarfile.TarInfo(name)
            if isinstance(content, bytes):
                info.size = len(content)
                tar.addfile(info, io.BytesIO(content))
            elif content[0] == "sym":
                info.type, info.linkname = tarfile.SYMTYPE, content[1]
                tar.addfile(info)
            elif content[0] == "hard":
                info.type, info.linkname = tarfile.LNKTYPE, content[1]
                tar.addfile(info)
            elif content[0] == "dev":
                info.type = tarfile.CHRTYPE
                tar.addfile(info)
            elif content[0] == "dir":
                info.type = tarfile.DIRTYPE
                tar.addfile(info)
    return buf.getvalue()


class WrapperCase(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self._tmp.cleanup)
        self.base = Path(self._tmp.name)
        for d in ("state", "results", "locks", "work"):
            (self.base / d).mkdir()
        self.policy = {"results_dir": str(self.base / "results"), "lock_dir": str(self.base / "locks"),
                       "tmp_dir": str(self.base / "work"), "slot_wait": 1}
        self.behavior = {}
        self.images = None
        self.version = None

    def write_policy(self):
        path = self.base / "policy.json"
        path.write_text(json.dumps(self.policy))
        return path

    def env(self, **extra):
        env = {"PATH": os.environ["PATH"], "PHORWARD_POLICY": str(self.write_policy()),
               "PHORWARD_ENGINE": str(FAKE), "FAKE_STATE": str(self.base / "state"),
               "FAKE_BEHAVIOR": json.dumps(self.behavior)}
        if self.images is not None:
            env["FAKE_IMAGES"] = self.images
        if self.version is not None:
            env["FAKE_VERSION"] = self.version
        env.update(extra)
        return env

    def run_wrapper(self, request, tar=None, timeout=60, env=None, via_ssh=False):
        tar = make_tar() if tar is None else tar
        e = env or self.env()
        argv = [sys.executable, "-I", str(WRAPPER)]
        if via_ssh:
            e = dict(e, SSH_ORIGINAL_COMMAND="phorward-run " + request)
        else:
            argv += request.split(" ")
        done = subprocess.run(argv, input=tar, capture_output=True, env=e, timeout=timeout)
        out = done.stdout.decode()
        return done.returncode, json.loads(out), done.stderr.decode()

    def calls(self):
        path = self.base / "state" / "calls.jsonl"
        return [json.loads(l) for l in path.read_text().splitlines()] if path.exists() else []

    def container_runs(self):
        return [c for c in self.calls() if c[0] == "run" and "--name" in c]

    def assert_clean(self):
        self.assertEqual(list((self.base / "work").iterdir()), [], "temp directory left behind")
        self.assertEqual(list((self.base / "state" / "containers").glob("*")), [], "container left behind")

    def refused(self, request, code, tar=None):
        rc, result, _ = self.run_wrapper(request, tar)
        self.assertEqual(rc, 2, result)
        self.assertEqual(result["verdict"], "error")
        self.assertEqual(result["error"]["code"], code, result)
        self.assertEqual(result["protocol"], 1)
        return result


class RunTests(WrapperCase):
    def test_passing_run(self):
        rc, result, _ = self.run_wrapper("run --protocol 1")
        self.assertEqual(rc, 0)
        self.assertEqual(result["verdict"], "passed")
        self.assertEqual([c["name"] for c in result["checks"]], ["lint", "phpcs", "phpstan", "phpunit"])
        self.assertTrue(all(c["status"] == "passed" for c in result["checks"]))
        self.assertEqual(result["image"], "phorward:smoke-php8.3")
        self.assertEqual(result["tool_versions"]["phpunit"], "11.5.57")
        self.assertEqual(result["db"], "mariadb")
        self.assertEqual(result["limits"]["applied"],
                         {"memory": "6g", "cpus": 2, "pids": 512, "timeout": 900})
        self.assertEqual(result["limits"]["clamped"], [])
        stored = json.loads((self.base / "results" / f"{result['run_id']}.json").read_text())
        self.assertEqual(stored, result)
        self.assert_clean()

    def test_sandbox_flags(self):
        self.run_wrapper("run --protocol 1 --checks phpcs")
        (call,) = self.container_runs()
        joined = " ".join(call)
        for flag in ("--network none", "--read-only", "--cap-drop ALL", "--security-opt no-new-privileges",
                     "--pids-limit 512", "--memory 6g", "--cpus 2", "--cpu-shares 256"):
            self.assertIn(flag, joined)
        mounts = [call[i + 1] for i, a in enumerate(call) if a == "-v"]
        self.assertEqual(len(mounts), 1, "only the tree is mounted")
        self.assertTrue(mounts[0].endswith(f":{MODULE_PATH}:ro"), mounts)
        self.assertIn("phorward:smoke-php8.3", call)
        self.assertNotIn("--privileged", call)

    def test_failed_check_does_not_stop_later_checks(self):
        self.behavior = {"phpcs": {"rc": 2, "output": "FOUND 3 ERRORS\n"}}
        rc, result, _ = self.run_wrapper("run --protocol 1")
        self.assertEqual(rc, 1)
        self.assertEqual(result["verdict"], "failed")
        status = {c["name"]: c["status"] for c in result["checks"]}
        self.assertEqual(status, {"lint": "passed", "phpcs": "failed", "phpstan": "passed", "phpunit": "passed"})
        phpcs = result["checks"][1]
        self.assertEqual((phpcs["exit_code"], phpcs["output"]), (2, "FOUND 3 ERRORS\n"))
        self.assert_clean()

    def test_checks_run_in_fixed_order_whatever_the_request_order(self):
        rc, result, _ = self.run_wrapper("run --protocol 1 --checks phpunit,lint")
        self.assertEqual([c["name"] for c in result["checks"]], ["lint", "phpunit"])

    def test_timeout_is_an_infrastructure_error_not_a_failure(self):
        self.behavior = {"phpcs": {"sleep": 60}}
        started = time.monotonic()
        rc, result, _ = self.run_wrapper("run --protocol 1 --timeout 2")
        self.assertLess(time.monotonic() - started, 20)
        self.assertEqual(rc, 70)
        self.assertEqual(result["verdict"], "error")
        self.assertEqual(result["error"]["code"], "timeout")
        status = {c["name"]: c["status"] for c in result["checks"]}
        self.assertEqual(status, {"lint": "passed", "phpcs": "error", "phpstan": "skipped", "phpunit": "skipped"})
        self.assertEqual(result["checks"][1]["reason"], "timeout")
        self.assertEqual(result["checks"][2]["reason"], "earlier_error")
        self.assertTrue(any(c[0] == "kill" for c in self.calls()))
        self.assert_clean()

    def test_oom_kill_is_an_infrastructure_error(self):
        self.behavior = {"phpunit": {"rc": 137, "oom": True, "output": "Killed\n"}}
        rc, result, _ = self.run_wrapper("run --protocol 1")
        self.assertEqual(rc, 70)
        self.assertEqual(result["error"]["code"], "oom_killed")
        self.assertEqual(result["checks"][-1]["status"], "error")
        self.assertEqual(result["checks"][-1]["reason"], "oom_killed")
        self.assert_clean()

    def test_command_that_cannot_start_is_not_a_failure(self):
        self.behavior = {"phpstan": {"rc": 127, "output": "exec: not found\n"}}
        rc, result, _ = self.run_wrapper("run --protocol 1")
        self.assertEqual(rc, 70)
        self.assertEqual(result["checks"][2]["reason"], "container_failed")

    def test_limits_above_policy_are_clamped_and_reported(self):
        rc, result, _ = self.run_wrapper("run --protocol 1 --checks lint --memory 64g --cpus 16 --pids 99999 --timeout 99999")
        self.assertEqual(rc, 0)
        limits = result["limits"]
        self.assertEqual(limits["requested"], {"memory": "64g", "cpus": 16, "pids": 99999, "timeout": 99999})
        self.assertEqual(limits["applied"], {"memory": "6g", "cpus": 2, "pids": 1024, "timeout": 900})
        self.assertEqual(limits["clamped"], ["memory", "cpus", "pids", "timeout"])
        (call,) = self.container_runs()
        self.assertIn("6g", call)
        self.assertNotIn("64g", call)

    def test_limits_below_policy_are_honoured(self):
        rc, result, _ = self.run_wrapper("run --protocol 1 --checks lint --memory 512m --cpus 1 --pids 64 --timeout 30")
        self.assertEqual(result["limits"]["applied"], {"memory": "512m", "cpus": 1, "pids": 64, "timeout": 30})
        self.assertEqual(result["limits"]["clamped"], [])

    def test_database_choice(self):
        self.run_wrapper("run --protocol 1 --checks phpunit --db sqlite")
        (sqlite,) = self.container_runs()
        self.assertIn("SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite", sqlite)
        self.assertNotIn("with-mariadb", sqlite)
        self.assertIn("4g", sqlite)
        (self.base / "state" / "calls.jsonl").unlink()
        self.run_wrapper("run --protocol 1 --checks phpunit --db mariadb")
        (maria,) = self.container_runs()
        self.assertIn("with-mariadb", maria)
        self.assertNotIn("SIMPLETEST_DB=sqlite://localhost//tmp/test.sqlite", maria)
        (self.base / "state" / "calls.jsonl").unlink()
        self.run_wrapper("run --protocol 1 --checks lint --db mariadb")
        (lint,) = self.container_runs()
        self.assertNotIn("with-mariadb", lint, "only phpunit gets a database")

    def test_selected_tests_become_separate_arguments(self):
        tar = make_tar({"smoke.info.yml": b"x", "tests/src/Unit/ATest.php": b"<?php\n"})
        self.run_wrapper("run --protocol 1 --checks phpunit --tests tests/src/Unit/ATest.php", tar)
        (call,) = self.container_runs()
        self.assertIn(f"{MODULE_PATH}/tests/src/Unit/ATest.php", call)

    def test_output_keeps_the_tail_and_reports_truncation(self):
        self.policy["max_output_bytes"] = 100
        self.behavior = {"phpunit": {"rc": 1, "output": "x" * 1000 + "SUMMARY\n"}}
        rc, result, _ = self.run_wrapper("run --protocol 1 --checks phpunit")
        check = result["checks"][0]
        self.assertTrue(check["output_truncated"])
        self.assertEqual(check["output_bytes"], 1008)
        self.assertEqual(len(check["output"]), 100)
        self.assertTrue(check["output"].endswith("SUMMARY\n"))

    def test_ssh_original_command_is_used_and_leading_word_dropped(self):
        rc, result, _ = self.run_wrapper("run --protocol 1 --checks lint", via_ssh=True)
        self.assertEqual(rc, 0)
        self.assertEqual([c["name"] for c in result["checks"]], ["lint"])

    def test_busy_server_gives_exit_75(self):
        self.policy["slots"] = 1
        self.policy["slot_wait"] = 1
        held = open(self.base / "locks" / "slot-0.lock", "a")
        fcntl.flock(held, fcntl.LOCK_EX)
        self.addCleanup(held.close)
        rc, result, _ = self.run_wrapper("run --protocol 1")
        self.assertEqual(rc, 75)
        self.assertEqual(result["error"]["code"], "busy")
        self.assertEqual(self.container_runs(), [])

    def test_slot_is_released_after_a_run(self):
        self.policy["slots"] = 1
        for _ in range(2):
            rc, _, _ = self.run_wrapper("run --protocol 1 --checks lint")
            self.assertEqual(rc, 0)

    def test_sigterm_cancels_and_cleans_up(self):
        self.behavior = {"lint": {"sleep": 60}}
        env = self.env()
        proc = subprocess.Popen([sys.executable, "-I", str(WRAPPER), "run", "--protocol", "1"],
                                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env)
        proc.stdin.write(make_tar())
        proc.stdin.close()
        deadline = time.monotonic() + 20
        while not list((self.base / "state" / "containers").glob("*.json")):
            self.assertLess(time.monotonic(), deadline, "container never started")
            time.sleep(0.05)
        proc.send_signal(signal.SIGTERM)
        out, _ = proc.communicate(timeout=30)
        self.assertEqual(proc.returncode, 70)
        self.assertEqual(json.loads(out)["error"]["code"], "cancelled")
        self.assert_clean()

    def test_client_disconnect_cancels_and_cleans_up(self):
        self.behavior = {"lint": {"sleep": 60}}
        proc = subprocess.Popen([sys.executable, "-I", str(WRAPPER), "run", "--protocol", "1"],
                                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                env=self.env())
        proc.stdin.write(make_tar())
        proc.stdin.close()
        deadline = time.monotonic() + 20
        while not list((self.base / "state" / "containers").glob("*.json")):
            self.assertLess(time.monotonic(), deadline, "container never started")
            time.sleep(0.05)
        proc.stdout.close()   # the reader (sshd) went away, taking stderr with it
        proc.stderr.close()
        started = time.monotonic()
        proc.wait(timeout=30)
        self.assertLess(time.monotonic() - started, 15)
        self.assertEqual(proc.returncode, 70)
        self.assert_clean()
        (stored,) = (self.base / "results").glob("*.json")
        self.assertEqual(json.loads(stored.read_text())["error"]["code"], "cancelled")


class RefusalTests(WrapperCase):
    def test_request_syntax(self):
        for request, code in [
            ("run", "bad_request"),
            ("run --protocol", "bad_request"),
            ("run --protocol 1 --checks lint;id", "bad_request"),
            ("run --protocol 1 --checks $(id)", "bad_request"),
            ("run --protocol 1 --bogus 1", "bad_request"),
            ("run --protocol 1 --protocol 1", "bad_request"),
            ("run --protocol 1 --memory lots", "bad_request"),
            ("run --protocol 1 --checks lint,nope", "bad_request"),
            ("run --protocol 1 --db oracle", "bad_request"),
            ("run --protocol 1 --timeout 0", "bad_request"),
            ("frobnicate --protocol 1", "bad_request"),
            ("run --protocol 1 --tests ../etc/passwd", "bad_request"),
            ("run --protocol 1 --tests /etc/passwd", "bad_request"),
            ("run --protocol 1 --tests a//b", "bad_request"),
            ("run --protocol 1 --checks lint --tests a/ATest.php", "bad_request"),
            ("run --protocol 1 --tests missing/ATest.php", "bad_request"),
            ("run --protocol 1 --shard 1/2", "bad_request"),
        ]:
            with self.subTest(request=request):
                self.refused(request, code)
                self.assertEqual(self.container_runs(), [])
                self.assert_clean()

    def test_protocol_mismatch_reports_the_server_version(self):
        result = self.refused("run --protocol 2", "protocol_mismatch")
        self.assertEqual(result["protocol"], 1)

    def test_empty_request(self):
        rc, result, _ = self.run_wrapper("")
        self.assertEqual((rc, result["error"]["code"]), (2, "bad_request"))

    def test_policy_refusals(self):
        self.refused("run --protocol 1 --profile php-library", "profile_not_allowed")
        self.refused("run --protocol 1 --php 8.5", "no_image")
        self.policy["allowed_php"] = ["8.5"]
        self.refused("run --protocol 1 --php 8.3", "php_not_allowed")

    def test_ambiguous_image_is_refused(self):
        self.images = "phorward:smoke-php8.3\nlocalhost/phorward:other-php8.3"
        self.refused("run --protocol 1", "ambiguous_image")

    def test_module_option_chooses_between_images(self):
        self.images = "phorward:smoke-php8.3\nphorward:other-php8.3"
        # the fake labels every image as module "smoke", so naming it still leaves two matches
        self.refused("run --protocol 1 --module smoke", "ambiguous_image")
        self.refused("run --protocol 1 --module nothing", "no_image")
        self.images = "phorward:smoke-php8.3"
        rc, result, _ = self.run_wrapper("run --protocol 1 --checks lint --module smoke")
        self.assertEqual(rc, 0)

    def test_podman_localhost_prefix_is_understood(self):
        self.images = "localhost/phorward:smoke-php8.3\nlocalhost/phorward:smoke-php8.3-20261105"
        rc, result, _ = self.run_wrapper("run --protocol 1 --checks lint")
        self.assertEqual(rc, 0)
        self.assertEqual(result["image"], "phorward:smoke-php8.3")

    def test_unreadable_policy_is_an_infrastructure_error(self):
        (self.base / "policy.json").write_text("{not json")
        env = self.env()
        env["PHORWARD_POLICY"] = str(self.base / "policy.json")
        # env() rewrites the policy file, so write the bad one afterwards
        (self.base / "policy.json").write_text("{not json")
        rc, result, _ = self.run_wrapper("run --protocol 1", env=env)
        self.assertEqual((rc, result["error"]["code"]), (70, "policy_invalid"))

    def test_unknown_policy_key_is_rejected(self):
        self.policy["max_memroy"] = "1g"
        rc, result, _ = self.run_wrapper("run --protocol 1")
        self.assertEqual((rc, result["error"]["code"]), (70, "policy_invalid"))


class ArchiveTests(WrapperCase):
    def test_unsafe_archives_are_refused_and_nothing_is_left_behind(self):
        cases = {
            "absolute path": {"/etc/evil": b"x"},
            "dotdot path": {"a/../../evil": b"x"},
            "absolute symlink": {"link": ("sym", "/etc/passwd")},
            "dotdot symlink": {"link": ("sym", "../outside")},
            "dotdot inside symlink": {"link": ("sym", "a/../..")},
            "device node": {"dev": ("dev", None)},
            "hard link out of tree": {"h": ("hard", "/etc/passwd")},
            "hard link to nothing": {"h": ("hard", "nothing")},
            "write through symlink": {"d": ("sym", "."), "d/x": b"x"},
            "empty": {},
        }
        for label, members in cases.items():
            with self.subTest(label):
                self.refused("run --protocol 1", "bad_archive", make_tar(members))
                self.assertEqual(self.container_runs(), [])
                self.assert_clean()

    def test_garbage_and_truncated_input(self):
        self.refused("run --protocol 1", "bad_archive", b"this is not a tar archive" * 100)
        self.refused("run --protocol 1", "bad_archive", make_tar()[:700])
        self.refused("run --protocol 1", "bad_archive", b"")
        self.assert_clean()

    def test_size_and_count_limits(self):
        self.policy["max_archive_files"] = 2
        self.refused("run --protocol 1", "bad_archive", make_tar({"a": b"1", "b": b"2", "c": b"3"}))
        self.policy["max_archive_files"] = 100
        self.policy["max_archive_file_bytes"] = 10
        self.refused("run --protocol 1", "bad_archive", make_tar({"a": b"x" * 11}))
        self.policy["max_archive_file_bytes"] = 100
        self.policy["max_archive_bytes"] = 15
        self.refused("run --protocol 1", "bad_archive", make_tar({"a": b"x" * 10, "b": b"y" * 10}))
        self.assert_clean()

    def test_safe_symlink_and_hard_link_are_accepted(self):
        tar = make_tar({"smoke.info.yml": b"x", "src": ("dir", None), "src/A.php": b"<?php\n",
                        "src/B.php": ("sym", "A.php"), "C.php": ("hard", "src/A.php")})
        rc, result, _ = self.run_wrapper("run --protocol 1 --checks lint", tar)
        self.assertEqual((rc, result["verdict"]), (0, "passed"))


class InfoTests(WrapperCase):
    def test_info(self):
        rc, info, _ = self.run_wrapper("info --protocol 1", b"")
        self.assertEqual(rc, 0)
        self.assertEqual(info["protocol"], 1)
        self.assertEqual(info["engine"]["name"], "docker")
        self.assertEqual(info["policy"]["max_memory"], "6g")
        self.assertEqual(info["policy"]["slots"], 2)
        self.assertEqual(info["images"], [{"profile": "drupal-module", "php": "8.3",
                                           "tag": "phorward:smoke-php8.3", "module_name": "smoke",
                                           "built": "2026-11-05"}])
        self.assertGreater(info["host"]["cpus"], 0)
        self.assertEqual(self.container_runs(), [])

    def test_info_protocol_mismatch(self):
        rc, result, _ = self.run_wrapper("info --protocol 9", b"")
        self.assertEqual((rc, result["error"]["code"]), (2, "protocol_mismatch"))

    def test_info_takes_no_run_options(self):
        rc, result, _ = self.run_wrapper("info --protocol 1 --checks lint", b"")
        self.assertEqual((rc, result["error"]["code"]), (2, "bad_request"))


if __name__ == "__main__":
    unittest.main()
