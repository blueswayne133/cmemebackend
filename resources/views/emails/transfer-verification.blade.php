<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Your Token Transfer - CMEME Platform</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            text-align: center;
            border-radius: 10px 10px 0 0;
            color: white;
        }
        .content {
            padding: 30px;
        }
        .verification-button {
            display: inline-block;
            padding: 15px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin: 20px 0;
        }
        .details {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 12px;
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Verify Your Token Transfer</h1>
            <p>CMEME Platform Security Verification</p>
        </div>
        
        <div class="content">
            <h2>Hello, {{ $user->username }}!</h2>
            <p>You have initiated a token transfer. Please verify this transaction to complete the transfer.</p>
            
            <div class="details">
                <h3>Transfer Details:</h3>
                <p><strong>Amount:</strong> {{ $transfer->amount }} {{ $transfer->currency }}</p>
                <p><strong>Recipient:</strong> {{ $transfer->recipient->username }} ({{ $transfer->recipient->uid }})</p>
                <p><strong>Description:</strong> {{ $transfer->description ?: 'No description provided' }}</p>
                <p><strong>Date:</strong> {{ $transfer->created_at->format('F j, Y \a\t g:i A') }}</p>
            </div>

            <div class="warning">
                <strong>⚠️ Security Notice:</strong> 
                This verification link will expire in 24 hours. If you did not initiate this transfer, 
                please ignore this email and contact support immediately.
            </div>

            <div style="text-align: center;">
                <a href="{{ $verificationUrl }}" class="verification-button">
                    Verify & Complete Transfer
                </a>
            </div>

            <p>If the button doesn't work, copy and paste this link into your browser:</p>
            <p style="word-break: break-all; color: #666; font-size: 12px;">
                {{ $verificationUrl }}
            </p>
        </div>
        
        <div class="footer">
            <p>This is an automated message from CMEME Platform. Please do not reply to this email.</p>
            <p>If you have any questions, contact our support team at support@cmeme-platform.com</p>
            <p>&copy; {{ date('Y') }} CMEME Platform. All rights reserved.</p>
        </div>
    </div>
</body>
</html>