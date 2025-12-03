<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap e2t-admin">
    <h1>Easy2Transfer</h1>
    <nav class="e2t-tabs">
        <button class="active" data-tab="felder">🧩 Felder</button>
        <button data-tab="kalender">📅 Kalender</button>
        <button data-tab="map">🗺️ Karte</button>
        <button data-tab="sync">🔄 Sync</button>
    </nav>

    <section id="tab-felder" class="tab active">
        <?php require_once __DIR__ . '/ui-felder.php'; ?>
    </section>

    <section id="tab-kalender" class="tab">
        <?php require_once __DIR__ . '/ui-kalender.php'; ?>
    </section>

    <section id="tab-map" class="tab">
        <?php require_once __DIR__ . '/ui-map.php'; ?>
    </section>

    <section id="tab-sync" class="tab">
        <?php require_once __DIR__ . '/ui-sync.php'; ?>
    </section>
</div>