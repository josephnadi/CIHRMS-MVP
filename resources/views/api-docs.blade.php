<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>CIHRMS API — Reference</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="icon" type="image/png" href="/favicon.ico" />
    <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements/styles.min.css" />
    <style>
        body { margin: 0; font-family: 'Open Sans', system-ui, sans-serif; }
        elements-api { display: block; height: 100vh; }
    </style>
</head>
<body>
    <elements-api
        apiDescriptionUrl="/api/v1/openapi.yaml"
        router="hash"
        layout="sidebar"
        tryItCredentialsPolicy="same-origin"
    ></elements-api>
    <script src="https://unpkg.com/@stoplight/elements/web-components.min.js"></script>
</body>
</html>
