<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Two-Factor Authentication Code</title>
</head>
<body>
    <h2>Your Two-Factor Authentication Code</h2>
    <p>Hello {{ $user->username }},</p>
    
    <p>Your verification code for {{ config('app.name') }} is:</p>
    
    <div style="font-size: 32px; font-weight: bold; letter-spacing: 5px; text-align: center; margin: 20px 0; padding: 10px; background: #f8f9fa; border-radius: 5px;">
        {{ $code }}
    </div>
    
    <p>This code will expire in 10 minutes.</p>
    
    <p>If you didn't request this code, please ignore this email.</p>
    
    <p>Best regards,<br>{{ config('app.name') }} Team</p>
</body>
</html>