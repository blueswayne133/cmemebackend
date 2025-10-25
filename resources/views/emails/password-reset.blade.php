<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
    <style>
        body {
            background-color: #f4f7fb;
            font-family: 'Arial', sans-serif;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 550px;
            margin: 40px auto;
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }
        h1 {
            font-size: 22px;
            color: #2a2a2a;
            margin-bottom: 10px;
        }
        p {
            font-size: 15px;
            line-height: 1.6;
            color: #555;
        }
        .button {
            display: inline-block;
            margin: 20px 0;
            padding: 12px 24px;
            background-color: #007bff;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
        }
        .button:hover {
            background-color: #0056b3;
        }
        .footer {
            text-align: center;
            border-top: 1px solid #eee;
            margin-top: 30px;
            padding-top: 15px;
            font-size: 13px;
            color: #888;
        }
        .highlight {
            color: #007bff;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Password Reset Request</h1>
        <p>Hello,</p>
        <p>We received a request to reset your password for your <span class="highlight">{{ $appName }}</span> account.</p>
        <p>Click the button below to reset your password. This link is valid for <strong>1 hour</strong>.</p>

        <a href="{{ $resetUrl }}" class="button" target="_blank">Reset Password</a>

        <p>If you did not request a password reset, please ignore this email or contact support if you believe this is an error.</p>

        <div class="footer">
            <p>Thank you for using {{ $appName }}.</p>
            <p>&copy; {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
