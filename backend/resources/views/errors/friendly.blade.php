<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} - DILG AttendanceHub</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            box-sizing: border-box;
            font: 16px/1.6 system-ui, sans-serif;
            color: #173052;
            background: #f3f7fd;
        }

        main {
            width: min(100%, 560px);
            padding: 36px;
            box-sizing: border-box;
            border: 1px solid #dfe7f2;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 18px 48px rgba(12, 35, 77, .1);
        }

        .brand {
            margin: 0 0 18px;
            color: #3655ce;
            font-size: 14px;
            font-weight: 750;
            letter-spacing: .03em;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(24px, 5vw, 32px);
            line-height: 1.2;
        }

        p {
            margin: 0 0 20px;
            color: #4b5d77;
        }

        a {
            display: inline-block;
            padding: 11px 16px;
            border-radius: 9px;
            color: #fff;
            background: #3655ce;
            font-weight: 700;
            text-decoration: none;
        }

        a:focus-visible {
            outline: 3px solid #86a0ff;
            outline-offset: 3px;
        }

        small {
            display: block;
            margin-top: 22px;
            color: #5d6e86;
            overflow-wrap: anywhere;
        }
    </style>
</head>

<body>
    <main>
        <div class="brand">DILG AttendanceHub</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <a href="/">Return to AttendanceHub</a>
        <small>Need help? Give your administrator this reference: {{ $requestId }}</small>
    </main>
</body>

</html>
