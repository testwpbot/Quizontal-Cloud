<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace FOSSBilling;

class ErrorPage
{
    /**
     * Your Quizontal Cloud storefront URL (the Laravel marketing site), e.g.
     * https://quizontalcloud.lk. Shows a "Back to Quizontal Cloud" button on the
     * error page. Leave empty ('') to hide the button.
     */
    private static string $storefrontUrl = '';

    /**
     * Returns the list of error codes and their specialized messages. All Error code parameters are optional.
     */
    private static function getCodes(): array
    {
        return [
            '1' => [
                'title' => 'Unable to find Composer Packages',
                'message' => 'The composer packages appear to be missing. This shouldn\'t happen if you are using a release version of FOSSBilling. If you are developer, you will need to install dependencies using <code>composer install</code>.',
                'link' => [
                    'label' => 'View more info on the composer website',
                    'href' => 'https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies',
                ],
                'report' => false,
            ],
            '2' => [
                'message' => 'For security reasons, you must delete the installation directory before you can use FOSSBilling. (<code>/install</code>)',
                'link' => [
                    'label' => 'View more info on the Getting Started guide',
                    'href' => 'https://fossbilling.org/docs/getting-started/shared#remove-the-installer',
                ],
                'report' => false,
            ],
            '3' => [
                'title' => 'Your Configuration is Empty',
                'message' => 'Your FOSSBilling configuration seems to either be empty or non-existent. You may need to re-install FOSSBilling, or re-create the <code>config.php</code> file based on the example config.',
                'link' => [
                    'label' => 'See the example config.',
                    'href' => 'https://github.com/FOSSBilling/FOSSBilling/blob/main/src/config-sample.php',
                ],
                'report' => false,
            ],
            '4' => [
                'title' => 'Migration is required',
                'message' => 'Legacy BoxBilling or FOSSBilling preview files have been found. The file structure within FOSSBilling, along with the configuration format, has since changed. 
 See the migration guide for assistance in migrating to the latest version of FOSSBilling.',
                'link' => [
                    'label' => 'Check the migration guide.',
                    'href' => 'https://fossbilling.org/docs/getting-started/migrate-from-boxbilling',
                ],
                'report' => false,
            ],
            '5' => [
                'title' => 'Missing .htaccess file',
                'message' => 'You appear to be running an Apache or LiteSpeed based webserver without a valid <b><em>.htaccess</em></b> file. Please create one using the default FOSSBilling .htaccess file.',
                'link' => [
                    'label' => 'Check the default .htaccess',
                    'href' => 'https://github.com/FOSSBilling/FOSSBilling/blob/main/src/.htaccess',
                ],
                'report' => false,
            ],
            // Incomplete server manager configuration. Is listed here so it's not forwarded to Sentry.io
            2001 => [
                'report' => false,
            ],
            // Incomplete registrar configuration. Is listed here so it's not forwarded to Sentry.io
            3001 => [
                'report' => false,
            ],
            // Incomplete payment gateway configuration. Is listed here so it's not forwarded to Sentry.io
            4001 => [
                'report' => false,
            ],
        ];
    }

    /* List of code categories. The "start" and "end" values are considered valid for a category.
     * (Example: an error code of 50 will match the "FOSSBilling Loader" category)
     */
    private static array $codeCategories = [
        'FOSSBilling Loader' => [
            'start' => 1,
            'end' => 50,
        ],
        'HTTP Error Codes' => [
            'start' => 400,
            'end' => 599,
        ],
        'Server Managers' => [
            'start' => 2000,
            'end' => 2999,
        ],
        'Domain Registration' => [
            'start' => 3000,
            'end' => 3999,
        ],
        'Payment Gateway' => [
            'start' => 4000,
            'end' => 4999,
        ],
    ];

    /**
     * Gets info for a specified error code, using placeholders for anything undefined.
     *
     * @param int $code The error code
     */
    public static function getCodeInfo(int|string $code): array
    {
        $code = intval($code);
        $errorDetails = [
            'title' => 'An error has occurred.',
            'link' => [
                'label' => 'View the FOSSBilling documentation',
                'href' => 'https://fossbilling.org/docs',
            ],
            'category' => 'None',
            'report' => true,
        ];

        $codes = self::getCodes();

        if (key_exists($code, $codes)) {
            $codeInfo = $codes[$code];
            $errorDetails = array_merge($errorDetails, $codeInfo);
        }

        $errorDetails['category'] = 'Generic';
        foreach (self::$codeCategories as $categoryName => $categoryRange) {
            if ($code >= $categoryRange['start'] && $code <= $categoryRange['end']) {
                $errorDetails['category'] = $categoryName;

                break;
            }
        }

        return $errorDetails;
    }

    /**
     * @param int    $code    Error code
     * @param string $message The original exception message
     */
    public function generatePage(int $code, string $message): never
    {
        $error = static::getCodeInfo($code);
        $error['message'] ??= "You've received a generic error message: <code> $message </code>";
        if (defined('INSTANCE_ID')) {
            $instanceID = INSTANCE_ID;
        } else {
            $instanceID = 'Unknown';
        }

        // Quizontal Cloud brand. The "Back to Quizontal Cloud" button is optional —
        // set the URL in the $storefrontUrl property at the top of this class.
        $storefrontUrl = trim(static::$storefrontUrl);
        $homeUrl = defined('SYSTEM_URL') && SYSTEM_URL !== '' ? SYSTEM_URL : '/';
        $displayCode = $code > 0 ? (string) $code : '500';
        $title = htmlspecialchars($error['title'], ENT_QUOTES, 'UTF-8');
        $category = htmlspecialchars((string) $error['category'], ENT_QUOTES, 'UTF-8');
        $instance = htmlspecialchars((string) $instanceID, ENT_QUOTES, 'UTF-8');
        $homeEsc = htmlspecialchars((string) $homeUrl, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        $storefrontButton = '';
        if ($storefrontUrl !== '') {
            $storefrontUrlEsc = htmlspecialchars($storefrontUrl, ENT_QUOTES, 'UTF-8');
            $storefrontButton = '<a class="qc-btn qc-btn-ghost" href="' . $storefrontUrlEsc . '">Back to Quizontal Cloud</a>';
        }

        $page = '
        <!DOCTYPE html>
        <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>' . $title . ' &middot; Quizontal Cloud</title>
            <style>
            :root {
                --qc-bg: #050508;
                --qc-card: #101018;
                --qc-border: rgba(255, 255, 255, 0.08);
                --qc-purple: #ba42ff;
                --qc-cyan: #00e1ff;
                --qc-text: #f4f4f8;
                --qc-muted: #9a9ab0;
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                background: radial-gradient(1200px 600px at 50% -10%, rgba(186, 66, 255, 0.16), transparent 60%),
                            radial-gradient(900px 500px at 80% 110%, rgba(0, 225, 255, 0.10), transparent 60%),
                            var(--qc-bg);
                color: var(--qc-text);
                font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
                font-size: 16px;
                line-height: 1.55;
                margin: 0;
                padding: 40px 20px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
            }

            .qc-error {
                width: 100%;
                max-width: 640px;
                text-align: center;
            }

            .qc-logo {
                height: 40px;
                margin-bottom: 28px;
            }

            .qc-code {
                font-family: "Space Grotesk", "DM Sans", sans-serif;
                font-size: 5rem;
                font-weight: 700;
                line-height: 1;
                background: linear-gradient(135deg, var(--qc-purple) 20%, var(--qc-cyan));
                -webkit-background-clip: text;
                background-clip: text;
                color: transparent;
                margin: 0 0 12px;
            }

            .qc-card {
                background: var(--qc-card);
                border: 1px solid var(--qc-border);
                border-radius: 20px;
                padding: 40px 36px;
                box-shadow: 0 30px 80px rgba(0, 0, 0, 0.55);
            }

            .qc-title {
                font-family: "Space Grotesk", "DM Sans", sans-serif;
                font-size: 1.6rem;
                font-weight: 600;
                margin: 0 0 8px;
            }

            .qc-tag {
                display: inline-block;
                font-size: 0.78rem;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: var(--qc-cyan);
                border: 1px solid rgba(0, 225, 255, 0.35);
                border-radius: 999px;
                padding: 4px 12px;
                margin: 0 0 18px;
            }

            .qc-message {
                color: var(--qc-text);
                margin: 0 0 6px;
                font-size: 1.02rem;
            }

            .qc-message code {
                background: rgba(255, 255, 255, 0.08);
                color: var(--qc-text);
                border-radius: 4px;
                padding: 1px 6px;
                font-size: 0.9em;
            }

            .qc-original {
                color: var(--qc-muted);
                margin: 0 0 6px;
                font-size: 0.95rem;
                display: none;
                word-break: break-word;
            }

            .qc-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                justify-content: center;
                margin-top: 26px;
            }

            .qc-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 12px 22px;
                border-radius: 12px;
                font-weight: 600;
                font-size: 0.95rem;
                text-decoration: none;
                cursor: pointer;
                border: 1px solid transparent;
                transition: transform 0.15s ease, filter 0.2s ease;
            }

            .qc-btn:hover { transform: translateY(-1px); }

            .qc-btn-primary {
                background: linear-gradient(135deg, var(--qc-purple), var(--qc-cyan));
                color: #07070c;
            }

            .qc-btn-primary:hover { filter: brightness(1.06); color: #07070c; }

            .qc-btn-ghost {
                background: transparent;
                color: var(--qc-text);
                border-color: var(--qc-border);
            }

            .qc-btn-ghost:hover { border-color: rgba(255, 255, 255, 0.25); }

            .qc-meta {
                margin-top: 26px;
                padding-top: 18px;
                border-top: 1px solid var(--qc-border);
                color: var(--qc-muted);
                font-size: 0.8rem;
            }

            .qc-meta span { white-space: nowrap; }

            .qc-meta .sep { margin: 0 8px; opacity: 0.5; }

            .qc-footer {
                margin-top: 22px;
                color: var(--qc-muted);
                font-size: 0.82rem;
            }

            a { color: #3291ff; }

            a:visited { color: inherit; text-decoration: none; }

            a:hover { text-decoration: underline; }

            </style>
            </head>
            <body>
                <div class="qc-error">
                    <img class="qc-logo" src="https://res.cloudinary.com/dt1sdefd6/image/upload/v1786643376/ChatGPT_Image_Aug_13_2026_11_17_40_PM-remove-bg-io_y5zxiu.png" alt="Quizontal Cloud">
                    <div class="qc-card">
                        <div class="qc-code">' . $displayCode . '</div>
                        <h1 class="qc-title">' . $title . '</h1>
                        <div class="qc-tag">' . $category . '</div>

                        <p class="qc-message" id="specialized">' . $error['message'] . '</p>
                        <p class="qc-original" id="original">' . $message . '</p>

                        <div class="qc-actions">
                            <a class="qc-btn qc-btn-primary" href="' . $homeEsc . '">Return to the client area</a>
                            ' . $storefrontButton . '
                            <button id="toggle" class="qc-btn qc-btn-ghost" onclick="toggle()">Show original message</button>
                        </div>

                        <div class="qc-meta">
                            <span>Instance ID: ' . $instance . '</span><span class="sep">&middot;</span>
                            <span>Error code: #' . $displayCode . '</span><span class="sep">&middot;</span>
                            <span>Component: ' . $category . '</span>
                        </div>
                    </div>
                    <div class="qc-footer">&copy; ' . $year . ' Quizontal Cloud &mdash; domains, hosting &amp; cloud VPS</div>
                </div>
                <script>
                    function toggle() {
                        var og = document.getElementById("original");
                        var specialized = document.getElementById("specialized");
                        var btn = document.getElementById("toggle");

                        if (og.style.display === "none" || og.style.display === "") {
                            og.style.display = "block";
                            specialized.style.display = "none";
                            btn.innerHTML = "Show specialized message";
                        } else {
                            og.style.display = "none";
                            specialized.style.display = "block";
                            btn.innerHTML = "Show original message";
                        }
                    }
                </script>
            </body>
        </html>';
        echo $page;
        exit;
    }
}
