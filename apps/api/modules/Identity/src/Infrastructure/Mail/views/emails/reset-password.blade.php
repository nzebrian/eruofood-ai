<!doctype html>
<html lang="en">
<body style="font-family: Arial, sans-serif; color: #1a1a1a; line-height: 1.6;">
    <h2>Reset your password</h2>
    <p>We received a request to reset your EruoFood AI password.</p>
    <p>
        <a href="{{ $resetUrl }}"
           style="display:inline-block;padding:12px 20px;background:#1B7A43;color:#fff;text-decoration:none;border-radius:6px;">
            Reset password
        </a>
    </p>
    <p>If the button doesn't work, paste this link into your browser:</p>
    <p><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
    <p style="color:#666;font-size:13px;">This link expires shortly. If you didn't request a reset, ignore this email.</p>
</body>
</html>
