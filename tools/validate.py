#!/usr/bin/env python3
"""Local lint and isolated checks; no store, database or Cloud connection."""
import pathlib
import subprocess
import sys

root = pathlib.Path(__file__).resolve().parents[1]
files = [p for p in root.rglob("*") if p.is_file() and ".git" not in p.relative_to(root).parts]
for p in files:
    if p.is_symlink():
        raise RuntimeError("Symlink forbidden: " + str(p.relative_to(root)))
    if p.suffix == ".php":
        result = subprocess.run(["php", "-l", str(p)], capture_output=True, text=True, timeout=30)
        if result.returncode:
            sys.exit(result.stdout + result.stderr)
for test in ["neurocheckoutconnector/tests/release_policy_test.php","neurocheckoutconnector/tests/request_body_decoder_test.php","neurocheckoutconnector/tests/secret_configuration_test.php","neurocheckoutconnector/tests/secret_configuration_no_key_test.php","neurocheckoutconnector/tests/signed_request_replay_test.php","neurocheckoutconnector/tests/upgrade_459_test.php","neurocheckoutconnector/tests/source_pull_protocol_test.php","neurocheckoutconnector/tests/automatic_source_binding_test.php","neurocheckoutconnector/tests/community_automatic_gateway_test.php","tests/security_boundaries.php"]:
    subprocess.run(["php", str(root / test)], cwd=root, check=True, timeout=60)
for test in ["neurocheckoutconnector/tests/prestashop_cart_amounts_test.php",
             "neurocheckoutconnector/tests/prestashop_source_directory_test.php",
             "neurocheckoutconnector/tests/upgrade_462_test.php",
             "neurocheckoutconnector/tests/upgrade_463_test.php",
             "neurocheckoutconnector/tests/upgrade_464_test.php"]:
    subprocess.run(["php", str(root / test)], cwd=root, check=True, timeout=60)
print("All PHP files linted; isolated tests passed. Real platform integration is not covered.")
