<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'Exception.php';
require 'PHPMailer.php';
require 'SMTP.php';

function sendMail(string $to, string $subject, string $body): bool|string {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'saramaoui556@gmail.com';
        $mail->Password   = 'jnwargyvhirxtknh'; // App Password without spaces
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('saramaoui556@gmail.com', 'EduAnalytics');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Subject  = $subject;
        $mail->Body     = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        return $mail->ErrorInfo;
    }
}