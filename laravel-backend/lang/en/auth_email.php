<?php
return [
    'verify_subject' => 'Verify your email — Haman',
    'verify_body' => "Hello :name,\n\nConfirm your email address to activate your account:\n\n:link\n\nThe link is valid for 48 hours. If you did not create an account, you can ignore this message.",
    'verification_sent' => 'A verification link has been sent to :email.',
    'verify_done' => 'Your email address is verified.',
    'verify_invalid' => 'That link is not valid or has expired. Please request a new one.',
    'already_verified' => 'Your email is already verified.',
    'send_failed' => 'The email could not be sent. Please try again shortly.',
    'smtp_unavailable' => 'Outbound email is not configured on this installation.',
    'banner_unverified' => 'Your email address is not verified yet.',
    'banner_resend' => 'Send the link again',

    // Recovery funnel (2026-10-10).
    'verification_resent' => 'A new verification link has been sent to :email — please check your spam folder too.',
    'no_account_for_address' => "We couldn't find an account with that email address.",
    'check_email_title' => 'Check your email',
    'check_email_body' => "We've sent a verification link to :email. Click it to activate your account — don't forget to check your spam folder.",
    'check_email_body_generic' => "We've sent a verification link to your email address. Click it to activate your account — don't forget to check your spam folder.",
    'didnt_get_it' => "Didn't get the email?",
    'resend_button' => 'Resend verification link',

    'reset_subject' => 'Reset your password — Haman',
    'reset_body' => "Hello :name,\n\nA password reset was requested for your account. Set a new password here:\n\n:link\n\nThe link is valid for 1 hour. If you did not request this, you can ignore this message — your password has not been changed.",
    'reset_link_sent_generic' => "If an account exists for that email address, we've sent a password reset link to it.",
    'reset_link_invalid' => 'That link is not valid or has expired. Please request a new one.',
    'reset_done' => 'Your password has been reset.',
    'forgot_password_title' => 'Forgot your password?',
    'forgot_password_body' => "Enter your email address and we'll send you a link to reset your password.",
    'forgot_password_link' => 'Forgot your password?',
    'reset_password_title' => 'Set a new password',
];
