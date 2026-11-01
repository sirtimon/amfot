<?php
// Run interactively inside the PHP container; no password in argv, env or output.
declare(strict_types=1);

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

try {
    chdir('/var/www/html');
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $input = new ArgvInput();
    $output = new ConsoleOutput();
    $helper = new QuestionHelper();
    $email = $helper->ask($input, $output, new Question('Email существующего администратора: '));
    if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid email');
    }
    $model = config('auth.providers.users.model');
    if (!is_string($model) || !is_subclass_of($model, Illuminate\Database\Eloquent\Model::class)) {
        throw new RuntimeException('Unsupported user provider');
    }
    $user = $model::where('email', $email)->first();
    // This deployment uses Voyager. Never create a user or grant an admin role.
    if (!$user || !method_exists($user, 'hasRole') || !$user->hasRole('admin')) {
        $output->writeln('Отказ: существующий Voyager admin с таким email не найден.');
        exit(1);
    }
    $question = new Question('Новый пароль (минимум 12 символов): ');
    $question->setHidden(true);
    $question->setHiddenFallback(false);
    $password = $helper->ask($input, $output, $question);
    $confirmation = new Question('Повторите новый пароль: ');
    $confirmation->setHidden(true);
    $confirmation->setHiddenFallback(false);
    $repeat = $helper->ask($input, $output, $confirmation);
    if (!is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72 || $password !== $repeat) {
        $output->writeln('Отказ: пароли должны совпадать, иметь минимум 12 символов и не более 72 байт.');
        exit(1);
    }
    if (!$helper->ask($input, $output, new ConfirmationQuestion('Изменить пароль этого администратора? [y/N] ', false))) {
        exit(1);
    }
    $user->password = Illuminate\Support\Facades\Hash::make($password);
    $user->setRememberToken(Illuminate\Support\Str::random(60));
    $user->save();
    unset($password, $repeat);
    $output->writeln('Пароль изменён, remember-token заменён. Активные сессии отдельно не удалялись.');
} catch (Throwable $e) {
    fwrite(STDERR, 'Сброс не выполнен (' . get_class($e) . '). Проверьте TTY, provider users и доступ к БД.' . PHP_EOL);
    exit(1);
}
