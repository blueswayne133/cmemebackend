<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>You Received Tokens - CMEME Platform</title>
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
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            padding: 30px;
            text-align: center;
            border-radius: 10px 10px 0 0;
            color: white;
        }
        .content {
            padding: 30px;
        }
        .gift-icon {
            font-size: 48px;
            color: #ff6b6b;
            text-align: center;
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
        .balance-info {
            background-color: #ffeaa7;
            border: 1px solid #fdcb6e;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .cta-button {
            display: inline-block;
            padding: 12px 25px;
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>You've Received Tokens! 🎉</h1>
            <p>New incoming transfer on CMEME Platform</p>
        </div>
        
        <div class="content">
            <div class="gift-icon">
                🎁
            </div>
            
            <h2>Hello, {{ $recipient->username }}!</h2>
            <p>Great news! You have received a token transfer from another user.</p>
            
            <div class="details">
                <h3>Transfer Details:</h3>
                <p><strong>Amount Received:</strong> {{ $transfer->amount }} {{ $transfer->currency }}</p>
                <p><strong>Sender:</strong> {{ $sender->username }} ({{ $sender->uid }})</p>
                <p><strong>Message:</strong> {{ $transfer->description ?: 'No message provided' }}</p>
                <p><strong>Transaction ID:</strong> TRX{{ $transfer->id }}{{ date('Ymd') }}</p>
                <p><strong>Received At:</strong> {{ $transfer->verified_at->format('F j, Y \a\t g:i A') }}</p>
            </div>

            <div class="balance-info">
                <h4>💰 Balance Updated</h4>
                <p>The tokens have been credited to your {{ $transfer->currency }} balance.</p>
                <p>You can now use these tokens for trading, staking, or withdrawals.</p>
            </div>

            <div style="text-align: center;">
                <a href="{{ url('/dashboard') }}" class="cta-button">
                    View My Dashboard
                </a>
            </div>

            <p>Thank you for being part of the CMEME Platform community!</p>
        </div>
        
        <div class="footer">
            <p>This is an automated notification from CMEME Platform.</p>
            <p>If you have any questions about this transfer, contact our support team.</p>
            <p>&copy; {{ date('Y') }} CMEME Platform. All rights reserved.</p>
        </div>
    </div>
</body>
</html>