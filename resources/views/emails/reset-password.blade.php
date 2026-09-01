<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>Cebu Province Virtual Maps</title>
</head>
<body style="margin:0; padding:0; background:#e7ece6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e7ece6;">
        <tr>
            <td align="center" style="padding:28px 12px 48px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%; background:#ffffff; border:1px solid #e4e9e5; border-radius:16px; overflow:hidden; font-family:Arial, Helvetica, sans-serif;">

                    <!-- Banner -->
                    <tr>
                        <td style="background:#15803d; background:linear-gradient(150deg,#15803d,#0e5f2d); padding:30px 40px 26px;">
                            <div style="font-family:Georgia,'Times New Roman',serif; font-weight:bold; font-size:30px; letter-spacing:1.5px; color:#ffffff;">Virtual Maps</div>
                            <div style="font-size:12px; letter-spacing:3px; text-transform:uppercase; color:#cfe6d6; margin-top:7px;">Cebu Province &middot; Virtual Maps</div>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:34px 40px 6px;">
                            <span style="display:inline-block; margin-bottom:16px; padding:5px 12px; border-radius:999px; background:#eef6f0; color:#0e5f2d; font-size:11px; font-weight:bold; letter-spacing:1.5px; text-transform:uppercase;">Account approved</span>

                            <h1 style="font-family:Georgia,'Times New Roman',serif; font-weight:normal; font-size:25px; line-height:1.2; margin:0 0 12px; color:#1b2420;">
                                @if($municipalName)
                                    Welcome, Municipality of {{ $municipalName }}
                                @else
                                    Set up your account
                                @endif
                            </h1>

                            <p style="font-size:15px; line-height:1.62; color:#5f6b64; margin:0 0 20px;">
                                Your access to the Cebu Province Virtual Maps CMS has been approved. Set a password to start uploading and managing your 360&deg; scenes.
                            </p>

                            <!-- Details -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e9e5; border-radius:12px; margin:0 0 26px;">
                                @if($municipalName)
                                <tr>
                                    <td style="padding:12px 16px; background:#eef6f0; font-size:14px; color:#8b968f;">Municipality</td>
                                    <td align="right" style="padding:12px 16px; background:#eef6f0; font-size:14px; font-weight:bold; color:#0e5f2d;">{{ $municipalName }}</td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding:12px 16px; border-top:1px solid #eef2ef; font-size:14px; color:#8b968f;">Role</td>
                                    <td align="right" style="padding:12px 16px; border-top:1px solid #eef2ef; font-size:14px; font-weight:bold; color:#1b2420;">{{ $roleLabel }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px; border-top:1px solid #eef2ef; font-size:14px; color:#8b968f;">Sign-in email</td>
                                    <td align="right" style="padding:12px 16px; border-top:1px solid #eef2ef; font-size:14px; font-weight:bold; color:#1b2420;">{{ $email }}</td>
                                </tr>
                            </table>

                            <!-- Button -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="padding:2px 0 20px;">
                                        <a href="{{ $url }}" style="display:inline-block; background:#15803d; color:#ffffff; text-decoration:none; font-weight:bold; font-size:15px; padding:14px 34px; border-radius:11px;">Set up my account</a>
                                    </td>
                                </tr>
                            </table>

                            <p style="text-align:center; font-size:13px; color:#8b968f; margin:0 0 26px;">
                                This secure link expires in <strong style="color:#5f6b64;">{{ $expireMinutes }} minutes</strong>.
                            </p>

                            <div style="height:1px; background:#eef2ef; margin:0 0 18px;"></div>

                            <p style="font-size:12.5px; color:#8b968f; line-height:1.6; margin:0 0 22px; word-break:break-all;">
                                Button not working? Copy and paste this link into your browser:<br>
                                <a href="{{ $url }}" style="color:#0e5f2d;">{{ $url }}</a>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background:#eef6f0; border-top:1px solid #e4e9e5; padding:22px 40px; color:#5f6b64; font-size:12.5px; line-height:1.65;">
                            <span style="font-family:Georgia,'Times New Roman',serif; font-weight:bold; color:#1b2420; letter-spacing:1px;">Virtual Maps</span> &middot; Cebu Province Virtual Maps<br>
                            <span style="color:#8b968f;">You received this because an administrator approved your access. If this wasn&rsquo;t expected, you can safely ignore this email.</span>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
