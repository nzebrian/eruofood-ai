<!doctype html>
<html lang="en">
<body style="font-family: Arial, sans-serif; color: #1a1a1a; line-height: 1.6;">
    <h2>Welcome to EruoFood AI, {{ $name }} 👋</h2>
    <p>Please confirm your email address to activate your account.</p>
    <p>
        <a href="{{ $verificationUrl }}"
           style="display:inline-block;padding:12px 20px;background:#1B7A43;color:#fff;text-decoration:none;border-radius:6px;">
            Verify email
        </a>
    </p>
    <p>If the button doesn't work, paste this link into your browser:</p>
    <p><a href="{{ $verificationUrl }}">{{ $verificationUrl }}</a></p>
    <p style="color:#666;font-size:13px;">If you didn't create an account, you can ignore this email.</p>
</body>
</html>
