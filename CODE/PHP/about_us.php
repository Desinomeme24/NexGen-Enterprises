<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

$displayName = $_SESSION['username'] ?? 'Client';
$fullName = $_SESSION['full_name'] ?? 'Client';
$profileImage = !empty($_SESSION['profile_image']) ? $_SESSION['profile_image'] : 'uploads/default.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <?php include __DIR__ . '/theme_init.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - NexGen</title>
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/about_us.css">
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/header.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>

<div class="about-page">

    <div class="about-particles" aria-hidden="true">
        <span></span><span></span><span></span><span></span><span></span><span></span>
    </div>

    <?php include 'header.php'; ?>

    <section class="about-sections">

        <div class="about-card animate-card animate-up">
            <div class="about-card-image">
                <img src="/NexGen/IMAGES/boutusupper.png" alt="About NexGen">
            </div>

            <div class="about-card-text">
                <span class="section-label">WHO WE ARE</span>
                <h2>About NexGen</h2>
                <p>
                    NexGen is a web-based management system developed to help Small and Medium Enterprises (SMEs) efficiently 
                    manage inventory, sales transactions, accounts receivable, and sales analytic reporting through one centralized platform.
                </p>
                <p>
                    The system integrates inventory monitoring, sales analytics, accounts receivable management, and an AI-powered chatbot assistant that enables users to retrieve business information quickly using conversational commands.
                </p>
                <p>
                    Designed to support the digital transformation of SMEs, NexGen simplifies business operations, improves data accuracy, strengthens inventory control, and assists business owners in making informed decisions through modern web technologies.
                </p>
            </div>
        </div>

        <div class="about-card reverse animate-card animate-left hover-fade-card">
            <div class="about-card-image">
                <img src="/NexGen/IMAGES/vission.jpg" alt="NexGen Vision">
            </div>

            <div class="about-card-text">
                <span class="section-label">OUR FUTURE</span>
                <h2>Vision</h2>
                <p>
                   Our vision is to empower Small and Medium Enterprises (SMEs) by transforming traditional business operations into intelligent, reliable, and technology-driven management solutions that promote operational efficiency, data accuracy, and sustainable business growth.
                </p>
            </div>
        </div>

        <div class="about-card animate-card animate-right hover-fade-card">
            <div class="about-card-image">
                <img src="/NexGen/IMAGES/mission.jpg" alt="NexGen Mission">
            </div>

            <div class="about-card-text">
                <span class="section-label">OUR GOAL</span>
                <h2>Mission</h2>
                <p>
                   NexGen aims to provide Small and Medium Enterprises (SMEs) with a secure, efficient, and user-friendly web-based management system that streamlines inventory management, sales transactions, accounts receivable monitoring, sales analytic reporting, and business information retrieval.
                </p>
                <p>
                   Through integrated management tools and an AI-powered chatbot assistant, the system enables businesses to improve operational efficiency, support data-driven decision-making, enhance productivity, and embrace digital transformation.
                </p>
            </div>
        </div>

    </section>

    <footer class="footer-section" id="footer-section">
        <div class="footer-top-line"></div>
        <p>
            &copy; 2026 NexGen. All rights reserved. |
            <a href="privacy_policy.php?return_to=about_us" style="color:#f7d98b; text-decoration:none; font-weight:700;">
                Privacy Policy
            </a>
            |
            <a href="privacy_policy.php?return_to=about_us#cookie-notice" style="color:#f7d98b; text-decoration:none; font-weight:700;">
                Cookie Notice
            </a>
        </p>
    </footer>

    <?php include 'chatbot.php'; ?>

<script src="/NexGen/CODE/JS/header.js"></script>
<script src="/NexGen/CODE/JS/about_us.js"></script>
</body>
</html>