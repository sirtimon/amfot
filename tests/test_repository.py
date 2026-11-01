import unittest
from check_repository import allowed


class PrivacyBoundary(unittest.TestCase):
    def test_private_inputs_are_rejected_even_when_force_added(self):
        for name in ['app/artisan', 'app/app/Models/User.php', 'backup.sql',
                     'deploy/backup.sql.gz', 'deploy/.env', '.env',
                     'docs/application.zip', 'docker/php/app.tar.gz',
                     'storage/logs/laravel.log', 'vendor/autoload.php',
                     '.github/workflows/upload-app.yml', 'deploy/id_ed25519']:
            with self.subTest(name=name):
                self.assertFalse(allowed(name))

    def test_documented_infrastructure_is_allowed(self):
        for name in ['compose.yaml', 'deploy/.env.example', 'deploy/check.sh',
                     'docs/operations.md', 'docker/php/Dockerfile']:
            with self.subTest(name=name):
                self.assertTrue(allowed(name))


if __name__ == '__main__':
    unittest.main()
