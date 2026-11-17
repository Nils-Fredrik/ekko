#!/usr/bin/env python3
"""Verify a labelled temporary restore through the connector; never print credentials or rows."""
import argparse
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--instance', required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'ekko-restore-t36-[a-z0-9-]+', args.instance):
        parser.error('Only a temporary T36 restore instance is allowed')
    project = 'ekko-514612'
    config = json.loads(subprocess.check_output(['gcloud', 'sql', 'instances', 'describe', args.instance,
        '--project', project, '--format=json'], text=True))
    settings = config['settings']
    if (config['region'] != 'europe-west1' or config['state'] != 'RUNNABLE'
            or settings.get('userLabels', {}).get('purpose') != 'restore-drill'
            or settings.get('connectorEnforcement') != 'REQUIRED'
            or settings['ipConfiguration'].get('authorizedNetworks')
            or settings['ipConfiguration'].get('sslMode') != 'ENCRYPTED_ONLY'):
        raise RuntimeError('Temporary restore safety checks failed')
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    with tempfile.TemporaryFile() as proxy_log:
        proxy = subprocess.Popen(['cloud-sql-proxy', '--address', '127.0.0.1', '--port', str(port),
            config['connectionName']], stdout=proxy_log, stderr=proxy_log)
        try:
            for _ in range(30):
                if proxy.poll() is not None:
                    raise RuntimeError('Cloud SQL proxy failed')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=1):
                        break
                except OSError:
                    time.sleep(1)
            else:
                raise RuntimeError('Cloud SQL proxy not ready')
            password = subprocess.check_output(['gcloud', 'secrets', 'versions', 'access', 'latest',
                '--secret=ekko-db-admin-password', '--project', project], text=True).rstrip('\n')
            env = dict(os.environ, PGPASSWORD=password, PGCONNECT_TIMEOUT='15')
            result = subprocess.run(['psql', '-X', '-h', '127.0.0.1', '-p', str(port), '-U', 'ekko_migrate',
                '-d', 'ekko', '-f', str(Path(__file__).with_name('verify-restored-database.sql'))],
                env=env, capture_output=True, text=True, timeout=120)
            if result.returncode:
                # SQL is fixed and synthetic; avoid echoing driver errors, connection strings or credentials.
                raise RuntimeError('Restored schema/role/RLS verification failed')
            print('PASS: restored schema, migration role, app role, two-tenant FORCE RLS, rollback')
        finally:
            proxy.terminate()
            try:
                proxy.wait(timeout=10)
            except subprocess.TimeoutExpired:
                proxy.kill()
                proxy.wait()


if __name__ == '__main__':
    main()
