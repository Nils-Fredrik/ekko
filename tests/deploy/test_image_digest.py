import json
import pathlib
import subprocess
import unittest


class ImageDigestTest(unittest.TestCase):
    repository = "europe-west1-docker.pkg.dev/ekko-514612/ekko/web"
    digest = "sha256:" + "a" * 64
    script = pathlib.Path(__file__).parents[2] / "scripts/image-digest.py"

    def select(self, entries):
        return subprocess.run(
            ["python3", str(self.script), self.repository],
            input=json.dumps(entries), text=True, capture_output=True,
        )

    def test_selects_registry_even_when_local_digest_is_first(self):
        result = self.select([
            "ekko-context-check@" + self.digest,
            self.repository + "@" + self.digest,
        ])
        self.assertEqual(result.returncode, 0)
        self.assertEqual(result.stdout.strip(), self.repository + "@" + self.digest)

    def test_rejects_different_repository_and_prefix_lookalike(self):
        result = self.select([
            self.repository + "-other@" + self.digest,
            self.repository.replace("/web", "/jobs") + "@" + self.digest,
        ])
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, "")

    def test_rejects_multiple_distinct_digests_for_repository(self):
        result = self.select([
            self.repository + "@" + self.digest,
            self.repository + "@sha256:" + "b" * 64,
        ])
        self.assertNotEqual(result.returncode, 0)

    def test_rejects_empty_or_malformed_data(self):
        for entries in [None, {}, [], [self.repository + "@sha256:abc"]]:
            with self.subTest(entries=entries):
                self.assertNotEqual(self.select(entries).returncode, 0)


if __name__ == "__main__":
    unittest.main()
