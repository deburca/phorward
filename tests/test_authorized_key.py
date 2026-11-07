"""Tests for server/authorized-key (needs ssh-keygen, which every host that runs these tests has)."""
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

TOOL = Path(__file__).resolve().parent.parent / "server" / "authorized-key"
RUN = "/opt/phorward/server/phorward-run"


@unittest.skipUnless(shutil.which("ssh-keygen"), "ssh-keygen not installed")
class AuthorizedKeyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls._tmp = tempfile.TemporaryDirectory()
        cls.dir = Path(cls._tmp.name)

    @classmethod
    def tearDownClass(cls):
        cls._tmp.cleanup()

    def keygen(self, name, *args):
        path = self.dir / name
        if not path.exists():
            subprocess.run(["ssh-keygen", "-q", "-N", "", "-C", "client one", "-f", str(path), *args], check=True)
        return path

    def run_tool(self, *args):
        done = subprocess.run([sys.executable, "-I", str(TOOL), *args], capture_output=True, text=True)
        return done.returncode, done.stdout, done.stderr

    def ok(self, *args):
        rc, out, err = self.run_tool(*args)
        self.assertEqual(rc, 0, err)
        self.assertEqual(out.count("\n"), 1, "exactly one line")
        return out.strip()

    def test_ed25519_line(self):
        key = self.keygen("ed", "-t", "ed25519")
        line = self.ok("--public-key", f"{key}.pub", "--run", RUN)
        pub = (self.dir / "ed.pub").read_text().strip().split()
        self.assertEqual(line, f'restrict,command="{RUN}" {pub[0]} {pub[1]} client one')

    def test_from_and_env(self):
        key = self.keygen("ed", "-t", "ed25519")
        line = self.ok("--public-key", f"{key}.pub", "--run", RUN, "--from", "192.0.2.0/24,*.example.org",
                       "--env", "PHORWARD_POLICY=/etc/phorward/policy.json", "--env", "PHORWARD_ENGINE=/usr/bin/podman")
        self.assertTrue(line.startswith(
            'restrict,command="env PHORWARD_POLICY=/etc/phorward/policy.json PHORWARD_ENGINE=/usr/bin/podman '
            f'{RUN}",from="192.0.2.0/24,*.example.org" ssh-ed25519 '))

    def test_ecdsa_is_accepted(self):
        key = self.keygen("ec", "-t", "ecdsa", "-b", "256")
        self.assertIn(" ecdsa-sha2-nistp256 ", self.ok("--public-key", f"{key}.pub", "--run", RUN))

    def test_strong_rsa_is_accepted_and_weak_rsa_refused(self):
        weak = self.keygen("rsa2048", "-t", "rsa", "-b", "2048")
        rc, _, err = self.run_tool("--public-key", f"{weak}.pub", "--run", RUN)
        self.assertNotEqual(rc, 0)
        self.assertIn("2048", err)
        strong = self.keygen("rsa3072", "-t", "rsa", "-b", "3072")
        self.assertIn(" ssh-rsa ", self.ok("--public-key", f"{strong}.pub", "--run", RUN))

    def refused(self, *args, mention=""):
        rc, out, err = self.run_tool(*args)
        self.assertNotEqual(rc, 0, out)
        self.assertEqual(out, "", "nothing is printed for a refused request")
        self.assertIn(mention, err)

    def test_private_key_is_refused(self):
        key = self.keygen("ed", "-t", "ed25519")
        self.refused("--public-key", str(key), "--run", RUN, mention="private key")

    def test_key_with_options_is_refused(self):
        pub = (self.dir / "ed.pub")
        self.keygen("ed", "-t", "ed25519")
        smuggled = self.dir / "smuggled.pub"
        smuggled.write_text('command="/bin/sh",no-pty ' + pub.read_text())
        self.refused("--public-key", str(smuggled), "--run", RUN, mention="public key type")

    def test_two_keys_are_refused(self):
        a, b = self.keygen("ed", "-t", "ed25519"), self.keygen("ec", "-t", "ecdsa", "-b", "256")
        both = self.dir / "both.pub"
        both.write_text((self.dir / "ed.pub").read_text() + (self.dir / "ec.pub").read_text())
        self.refused("--public-key", str(both), "--run", RUN, mention="exactly one")

    def test_bad_arguments(self):
        key = self.keygen("ed", "-t", "ed25519")
        pub = f"{key}.pub"
        self.refused("--public-key", pub, "--run", "relative/phorward-run", mention="--run")
        self.refused("--public-key", pub, "--run", "/opt/../etc/phorward-run", mention="--run")
        self.refused("--public-key", pub, "--run", '/opt/x" ; id ; "', mention="--run")
        self.refused("--public-key", pub, "--run", RUN, "--env", "lower=x", mention="--env")
        self.refused("--public-key", pub, "--run", RUN, "--env", "A=$(id)", mention="--env")
        self.refused("--public-key", pub, "--run", RUN, "--env", 'A=b"c', mention="--env")
        self.refused("--public-key", pub, "--run", RUN, "--from", '1.2.3.4" ,no-restrict', mention="--from")
        self.refused("--public-key", str(self.dir / "missing.pub"), "--run", RUN, mention="cannot read")

    def test_comment_with_quotes_is_refused(self):
        self.keygen("ed", "-t", "ed25519")
        pub = (self.dir / "ed.pub").read_text().split()
        bad = self.dir / "badcomment.pub"
        bad.write_text(f'{pub[0]} {pub[1]} evil"comment\n')
        self.refused("--public-key", str(bad), "--run", RUN, mention="comment")


if __name__ == "__main__":
    unittest.main()
