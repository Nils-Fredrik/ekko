#!/usr/bin/env python3
"""Select the exact pushed repository from Docker's local RepoDigests JSON."""

import json
import re
import sys


def select_digest(repository, entries):
    if not isinstance(entries, list):
        raise ValueError("RepoDigests must be a list")
    pattern = re.compile(re.escape(repository) + r"@sha256:[a-f0-9]{64}")
    matches = {
        entry for entry in entries if isinstance(entry, str) and pattern.fullmatch(entry)
    }
    if len(matches) != 1:
        raise ValueError("Expected exactly one digest for the pushed repository")
    return matches.pop()


if __name__ == "__main__":
    try:
        if len(sys.argv) != 2:
            raise ValueError("Expected repository argument")
        print(select_digest(sys.argv[1], json.load(sys.stdin)))
    except (ValueError, TypeError) as error:
        sys.exit(str(error))
