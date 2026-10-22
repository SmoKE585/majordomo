<?php

/** System helpers. Public names and argument defaults are part of the module API. */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function SendMail($from, $to, $subj, $body = "", $attach = "")
{
    if ($body == '') {
        $body = $subj;
        $subj = strip_tags($subj);
        $subj = str_replace("\n", " ", $subj);
        if (mb_strlen($subj) > 50) {
            $subj = mb_substr($subj, 0, 50) . '...';
        }
    }
    return SendMail_HTML($from, $to, $subj, "<pre>" . htmlspecialchars($body) . "</pre>", $attach);
}

function SendMail_HTML($from, $to, $subj, $body = "", $attach = "")
{
    $max_file_size = 50 * 1024 * 1024; //50Mb

    if ($body == '') {
        $body = $subj;
        $subj = strip_tags($subj);
        $subj = str_replace("\n", " ", $subj);
        if (mb_strlen($subj) > 50) {
            $subj = mb_substr($subj, 0, 50) . '...';
        }
    }

    if (defined('SETTINGS_MAIL_TYPE')) {
        $mailer_type = SETTINGS_MAIL_TYPE; //sendmail
    } else {
        $mailer_type = 'sendmail'; //sendmail
    }
    if (defined('SETTINGS_MAIL_HOST')) {
        $smtp_host = SETTINGS_MAIL_HOST;
    } else {
        $smtp_host = '';
    }
    if (defined('SETTINGS_MAIL_AUTH')) {
        if (SETTINGS_MAIL_AUTH) $smtp_auth = true;
        else $smtp_auth = false;
    } else {
        $smtp_auth = true;
    }
    if (defined('SETTINGS_MAIL_USER')) {
        $smtp_user = SETTINGS_MAIL_USER;
    } else {
        $smtp_user = '';
    }
    if (defined('SETTINGS_MAIL_PASSWORD')) {
        $smtp_password = SETTINGS_MAIL_PASSWORD;
    } else {
        $smtp_password = '';
    }
    if (defined('SETTINGS_MAIL_SECURE')) {
        $smtp_secure = SETTINGS_MAIL_SECURE;
    } else {
        $smtp_secure = '';
    }
    if (defined('SETTINGS_MAIL_PORT')) {
        $smtp_port = SETTINGS_MAIL_PORT;
    } else {
        $smtp_port = 465;
    }

    if ($mailer_type == 'smtp') {
        if ($smtp_auth && (!$smtp_user || !$smtp_password)) {
            DebMes("SMTP username/password is not set", 'sendmail', 'error');
            return false;
        }
        if (!$smtp_host) {
            DebMes("SMTP host is not set", 'sendmail', 'error');
            return false;
        }
    }
    $mail = new PHPMailer(true);
    try {
        if ($mailer_type == 'sendmail') {
            $mail->isSendmail();
        } else {
            $mail->Host = $smtp_host;  // Specify main and backup SMTP servers
            $mail->SMTPAuth = $smtp_auth;                               // Enable SMTP authentication
            $mail->Username = $smtp_user;                 // SMTP username
            $mail->Password = $smtp_password;                           // SMTP password
            $mail->SMTPSecure = $smtp_secure;                            // Enable TLS encryption, `ssl` also accepted
            $mail->Port = $smtp_port;
            $mail->isSMTP();                                      // Set mailer to use SMTP
        }
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($smtp_user, $from);
        $mail->addAddress($to);
        $mail->isHTML(true);                                  // Set email format to HTML
        $mail->Subject = $subj;
        $mail->Body = $body;

        if (is_array($attach)) {
            $total_file_size = 0;
            $total = count($attach);
            for ($i = 0; $i < $total; $i++) {
                if (file_exists($attach[$i])) {
                    $total_file_size += filesize($attach[$i]);
                    if ($total_file_size <= $max_file_size) {
                        $mail->addAttachment($attach[$i], basename($attach[$i]));
                    }
                }
            }
        } elseif ((file_exists($attach)) && ($attach != "")) {
            $total_file_size = filesize($attach);
            if ($total_file_size <= $max_file_size) {
                $mail->addAttachment($attach, basename($attach));
            }
        }
        $result = $mail->send();
    } catch (Exception $e) {
        DebMes("Message could not be sent: " . $mail->ErrorInfo, 'sendmail', 'error');
        return false;
    }
    return $result;
}
