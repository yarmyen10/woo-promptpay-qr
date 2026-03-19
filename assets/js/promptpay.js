/**
 * PromptPay QR — Frontend JS
 * จัดการ Drag & Drop, Preview, และส่งสลิปผ่าน AJAX
 */
(function () {
    'use strict';

    // รอ DOM พร้อม
    document.addEventListener('DOMContentLoaded', initPromptPay);

    function initPromptPay() {
        const wrap = document.getElementById('promptpay-wrap');
        if (!wrap) return; // ไม่มี shortcode ในหน้านี้

        const dropZone   = wrap.querySelector('#pp-drop-zone');
        const fileInput  = wrap.querySelector('#pp-file-input');
        const previewWrap= wrap.querySelector('#pp-preview-wrap');
        const previewImg = wrap.querySelector('#pp-preview-img');
        const changeBtn  = wrap.querySelector('#pp-change-btn');
        const submitBtn  = wrap.querySelector('#pp-submit-btn');
        const resultBox  = wrap.querySelector('#pp-result');

        let currentFile = null;

        // ===== คลิกเปิด file dialog =====
        dropZone.addEventListener('click', () => fileInput.click());

        // ===== Drag & Drop =====
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('is-drag-over');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('is-drag-over');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('is-drag-over');
            const file = e.dataTransfer.files[0];
            if (file) handleFile(file);
        });

        // ===== เลือกไฟล์ปกติ =====
        fileInput.addEventListener('change', () => {
            if (fileInput.files[0]) handleFile(fileInput.files[0]);
        });

        // ===== เปลี่ยนสลิป =====
        changeBtn.addEventListener('click', resetUpload);

        // ===== ส่งสลิป =====
        submitBtn.addEventListener('click', submitSlip);

        // ---- Functions ----

        function handleFile(file) {
            if (!file.type.startsWith('image/')) {
                showResult('กรุณาเลือกไฟล์รูปภาพเท่านั้น', false);
                return;
            }

            currentFile = file;
            const reader = new FileReader();

            reader.onload = (e) => {
                previewImg.src          = e.target.result;
                dropZone.style.display  = 'none';
                previewWrap.style.display = 'block';
                submitBtn.disabled      = false;
                resultBox.style.display = 'none';
            };

            reader.readAsDataURL(file);
        }

        function resetUpload() {
            currentFile              = null;
            fileInput.value          = '';
            dropZone.style.display   = 'block';
            previewWrap.style.display= 'none';
            submitBtn.disabled       = true;
            resultBox.style.display  = 'none';
        }

        function submitSlip() {
            if (!currentFile) return;

            setLoading(true);
            resultBox.style.display = 'none';

            const formData = new FormData();
            formData.append('action',   'promptpay_verify_slip');
            formData.append('nonce',    submitBtn.dataset.nonce);
            formData.append('order_id', submitBtn.dataset.order || '0');
            formData.append('slip',     currentFile);

            const ajaxUrl = (typeof PromptPayData !== 'undefined')
                ? PromptPayData.ajaxUrl
                : '/wp-admin/admin-ajax.php';

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData,
            })
            .then((res) => res.json())
            .then((data) => {
                if (data.success) {
                    showResult(data.data.message, true);
                    if (data.data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.data.redirect;
                        }, 1500);
                    }
                } else {
                    showResult(data.data.message, false);
                    submitBtn.disabled = false;
                }
            })
            .catch(() => {
                showResult('เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง', false);
                submitBtn.disabled = false;
            })
            .finally(() => setLoading(false));
        }

        function setLoading(loading) {
            submitBtn.querySelector('.pp-btn-text').style.display    = loading ? 'none'   : 'inline';
            submitBtn.querySelector('.pp-btn-loading').style.display = loading ? 'inline' : 'none';
            if (loading) submitBtn.disabled = true;
        }

        function showResult(message, success) {
            resultBox.textContent   = (success ? '✅ ' : '❌ ') + message;
            resultBox.className     = 'pp-result ' + (success ? 'is-success' : 'is-error');
            resultBox.style.display = 'block';
        }
    }
})();
