"""Exercise the operator probe without Docker, an application or a real network."""
import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest


@unittest.skipUnless(os.name == 'posix' and shutil.which('sh'), 'requires POSIX shell')
class OperatorProbe(unittest.TestCase):
    def probe(self, status='200', services='app\nnginx\nworker\nscheduler\ndb', curl_exit='0'):
        with tempfile.TemporaryDirectory() as directory:
            folder = pathlib.Path(directory)
            (folder / 'docker').write_text('''#!/bin/sh
case "$*" in
  'compose ps --services --status running') printf '%s\\n' "$TEST_RUNNING_SERVICES" ;;
esac
exit 0
''')
            (folder / 'curl').write_text('''#!/bin/sh
printf '%s' "$TEST_HTTP_STATUS"
exit "$TEST_CURL_EXIT"
''')
            for name in ['docker', 'curl']:
                (folder / name).chmod(0o755)
            env = dict(os.environ, PATH=directory + os.pathsep + os.environ['PATH'],
                       TEST_HTTP_STATUS=status, TEST_RUNNING_SERVICES=services,
                       TEST_CURL_EXIT=curl_exit)
            script = pathlib.Path(__file__).resolve().parents[1] / 'deploy/check.sh'
            return subprocess.run(['sh', str(script)], capture_output=True, text=True, env=env)

    def test_ready_services_and_http_200_pass(self):
        self.assertEqual(self.probe().returncode, 0)

    def test_redirect_is_not_a_successful_login_page(self):
        result = self.probe(status='302')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('FAIL HTTPS login', result.stderr)

    def test_missing_worker_is_reported(self):
        result = self.probe(services='app\nnginx\nscheduler\ndb')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('worker', result.stderr)

    def test_tls_failure_is_not_ignored(self):
        self.assertNotEqual(self.probe(curl_exit='60').returncode, 0)
