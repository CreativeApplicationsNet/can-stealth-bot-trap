<?php
if (!defined('ABSPATH')) exit;

// Prevent W3TC from caching the block/quiz page
define('DONOTCACHEPAGE', true);

// Prevent caching of this page - browsers must always fetch fresh
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

global $sbt_core; // Access the core instance
$opts = get_option('sbt_settings', []);
$is_quiz_mode = (isset($opts['block_mode']) && $opts['block_mode'] === 'quiz') || isset($_GET['preview_quiz']);

// Check if user is still actually banned (but not in preview mode)
if (!defined('SBT_PREVIEW_MODE') && $sbt_core && !$sbt_core->is_banned()) {
    // User was unblocked, let them through
    error_log('[SBT] User is not banned, allowing access');
    wp_redirect(home_url('/'));
    die();
}

$quiz_text = "";
// Only generate quiz if in quiz mode AND not a POST submission
if ($is_quiz_mode && $sbt_core && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $quiz_data = $sbt_core->get_or_create_quiz();
    $quiz_text = $quiz_data['question'];
} elseif ($is_quiz_mode && $sbt_core && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // On POST, retrieve the existing quiz to display the same question
    $quiz_data = $sbt_core->get_or_create_quiz();
    $quiz_text = $quiz_data['question'];
}

$reason = isset($_GET['reason']) ? sanitize_text_field($_GET['reason']) : 'blocked';
$is_rate_limit = ($reason === 'rate_limit');
$status_code   = $is_rate_limit ? 429 : 403;
http_response_code($status_code);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Access Denied</title>
    <meta name="viewport" content="width=device-width, initial-scale=0.85">

    <style>

        :root {
        	--backgroundcolor: #0C0909;
        	--bodycolor: #DADADA;
        	--linecolor: #DADADA;
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--backgroundcolor);
            color: var(--bodycolor);
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            text-align: center;
        }
        .box {
            max-width: 520px;
            padding-top: 20px;
            padding-left: 40px;
            padding-right: 40px;
            padding-bottom: 20px;
            margin-left: 20px;
            margin-right: 20px;
            background: transparent;
            border-radius: 0px;
            border: 1px solid #DADADA;

        }
        h1 {
            margin-bottom: 20px;
            font-size: 28px;
            margin-top:0px;
            font-weight: normal;
            font-style: normal;
        }
        p {
            opacity: .85;
            line-height: 1.6;
        }
        small {
            display: block;
            margin-top: 20px;
            opacity:1;
        }

        /* Styles for Quiz Form */
        .quiz-form {
            margin-top: 20px;
        }
        .quiz-question {
            font-size: 28px;
            margin-bottom: 28px;
        }
        .quiz-input {
            background: transparent;
            border: 1px solid var(--linecolor);
            padding: 10px;
            width: 80px;
            color: var(--bodycolor);
            text-align: center;
            font-size: 18px;
            outline: none;
            margin-right: 10px;
        }
        .quiz-submit {
            background: var(--backgroundcolor);
            color: var(--bodycolor);
            border: none;
            padding: 11px 20px;
            cursor: pointer;
            font-size: 18px;
            transition: opacity 0.2s;
            border: 1px solid var(--linecolor);
        }
        .quiz-submit:hover {
            opacity: 0.8;
        }

        .blink {
            animation: blinker 1s linear infinite;
        }

        @keyframes blinker {
          0% { opacity: 1.0; }
          50% { opacity: 0; }
          100% { opacity: 1.0; }
        }
    </style>
</head>
<body>
    <div class="box">
        <?php if ($is_quiz_mode): ?>
            <h1>Are you human?</h1>
            <p>Please solve this simple math problem to prove you are not a bot.</p>

            <div class="quiz-form">
                <form method="POST" action="">
                    <?php wp_nonce_field('sbt_solve_quiz', 'sbt_quiz_nonce'); ?>
                    <div class="quiz-question">
                        <?php echo esc_html($quiz_text); ?>
                    </div>
                    <input type="number" name="sbt_quiz_answer" class="quiz-input" required autofocus>
                    <button type="submit" class="quiz-submit">Submit</button>
                </form>
            </div>

        <?php elseif ($is_rate_limit): ?>
            <h1>Too Many Requests</h1>
            <p>You've made too many requests in a short period of time.</p>
            <p>Please come back later.</p>
        <?php else: ?>
            <h1>Access Denied</h1>
            <p>Your access has been restricted for security reasons.</p>
            <p>Please come back later.</p>
        <?php endif; ?>

        <small>CAN—V6 Stealth Bot Block v2.5.0</small>
    </div>
</body>
</html>
