<?php
if (!defined('ABSPATH')) {
    exit;
}

class CRM_Melipayamak
{

    private string $username;
    private string $password;

    public function __construct()
    {
        $this->username = (string) get_option('crm_melipayamak_username', '');
        $this->password = (string) get_option('crm_melipayamak_password', '');
    }

    public function sendOtp(string $to, string $bodyId, array $args): bool
    {
        if (empty($this->username) || empty($this->password)) {
            error_log('Melipayamak Error: Username or Password not configured.');
            return false;
        }

        $url = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';

        // Prepare text argument parameters format required by Melipayamak Rest API
        $text_str = implode(';', array_map('sanitize_text_field', $args));

        $body = [
            'username' => $this->username,
            'password' => $this->password,
            'text'     => $text_str,
            'to'       => sanitize_text_field($to),
            'bodyId'   => (int) $bodyId
        ];

        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'timeout'   => 15,
            'headers'   => [
                'Content-Type' => 'application/json; charset=utf-8'
            ],
            'body'      => wp_json_encode($body),
            'data_format' => 'body'
        ]);

        if (is_wp_error($response)) {
            error_log('Melipayamak HTTP Request Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            error_log("Melipayamak Error HTTP {$response_code}: {$response_body}");
            return false;
        }

        $data = json_decode($response_body, true);

        // Melipayamak returns a numeric status/recId on success or specific error codes (> 15 chars for recId typically)
        if (isset($data['Value']) && (is_numeric($data['Value']) && (float)$data['Value'] > 15)) {
            return true;
        }

        if (isset($data['RetStatus']) && (int)$data['RetStatus'] === 1) {
            return true;
        }

        error_log('Melipayamak Gateway Error Response: ' . $response_body);
        return false;
    }
}
