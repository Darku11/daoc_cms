<?php
// SPDX-License-Identifier: GPL-3.0-only
if (($GLOBALS['cms_settings']['mod_faq'] ?? '1') === '0' && ($GLOBALS['userPriv'] ?? 0) < 4) {
    return;
}
$stmt_faq = $db->query("SELECT * FROM faq ORDER BY category, sort_order ASC");
$faqs = [];

while ($row = $stmt_faq->fetch()) {
    $faqs[$row['category']][] = $row;
}
?>

<div class="admin-container">
    <div class="admin-box faq-box">
        <div class="faq-intro">
            <?php echo $data['content'] ?? ''; ?>
        </div>

        <?php if (!empty($faqs)): ?>
            <?php foreach ($faqs as $category => $items): ?>
                <h3 class="faq-category-title"><?php echo h($category); ?></h3>

                <?php foreach ($items as $faq): ?>
                    <div class="faq-item" onclick="toggleFaq(<?php echo (int)$faq['id']; ?>)">
                        <div class="faq-question">
                            <span><?php echo h($faq['question']); ?></span>
                            <i class="fas fa-chevron-down faq-chevron" id="icon-<?php echo (int)$faq['id']; ?>"></i>
                        </div>
                        <div id="answer-<?php echo (int)$faq['id']; ?>" class="faq-answer" style="display:none;">
                            <?php echo nl2br(h($faq['answer'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="faq-empty">
                <i class="fas fa-scroll faq-empty-icon"></i><br>
                <?= t('faq_no_questions', [], 'The library is currently being written. Please check back later.') ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleFaq(id) {
    const answer = document.getElementById('answer-' + id);
    const icon = document.getElementById('icon-' + id);
    const container = answer.parentElement;

    if (answer.style.display === 'none') {
        answer.style.display = 'block';
        icon.style.transform = 'rotate(180deg)';
        container.classList.add('faq-item--open');
    } else {
        answer.style.display = 'none';
        icon.style.transform = 'rotate(0deg)';
        container.classList.remove('faq-item--open');
    }
}
</script>