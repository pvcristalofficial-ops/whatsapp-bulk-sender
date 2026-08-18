// assets/js/app.js

document.addEventListener('DOMContentLoaded', function () {
    // -------------------------------------------------------------
    // 1. Template Live Preview Compiler
    // -------------------------------------------------------------
    const templateForm = document.getElementById('templateForm');
    if (templateForm) {
        const nameInput = document.getElementById('templateName');
        const langInput = document.getElementById('templateLanguage');
        const headerInput = document.getElementById('headerText');
        const bodyInput = document.getElementById('bodyText');
        const footerInput = document.getElementById('footerText');
        const buttonsContainer = document.getElementById('buttonsWrapper');

        function updatePreview() {
            // Update Headers
            const previewHeader = document.getElementById('previewHeader');
            if (previewHeader) {
                if (headerInput && headerInput.value.trim() !== '') {
                    previewHeader.textContent = headerInput.value;
                    previewHeader.style.display = 'block';
                } else {
                    previewHeader.style.display = 'none';
                }
            }

            // Update Body & Format variables {{1}}, {{2}}...
            const previewBody = document.getElementById('previewBody');
            if (previewBody && bodyInput) {
                let text = bodyInput.value;
                // Replace formatting: *bold* -> <strong>bold</strong>, _italic_ -> <em>italic</em>
                text = text
                    .replace(/\*(.*?)\*/g, '<strong>$1</strong>')
                    .replace(/_(.*?)_/g, '<em>$1</em>');
                
                // Replace variables {{1}}, {{2}}, etc. with highlighted badges
                text = text.replace(/\{\{(\d+)\}\}/g, function(match, number) {
                    let field = 'Variable';
                    if (number === '1') field = 'Name';
                    else if (number === '2') field = 'City';
                    else if (number === '3') field = 'Course';
                    return `<span class="badge bg-success font-monospace" style="font-size:0.75rem;">{{${number}:${field}}}</span>`;
                });
                
                previewBody.innerHTML = text || '<em>Message body preview will appear here...</em>';
            }

            // Update Footer
            const previewFooter = document.getElementById('previewFooter');
            if (previewFooter) {
                if (footerInput && footerInput.value.trim() !== '') {
                    previewFooter.textContent = footerInput.value;
                    previewFooter.style.display = 'block';
                } else {
                    previewFooter.style.display = 'none';
                }
            }

            // Update Buttons
            const previewButtons = document.getElementById('previewButtons');
            if (previewButtons) {
                previewButtons.innerHTML = '';
                if (buttonsContainer) {
                    const buttonRows = buttonsContainer.querySelectorAll('.button-row');
                    buttonRows.forEach(row => {
                        const typeSelect = row.querySelector('.btn-type');
                        const textInput = row.querySelector('.btn-text');
                        
                        if (textInput && textInput.value.trim() !== '') {
                            let icon = 'fa-comment';
                            if (typeSelect.value === 'PHONE') icon = 'fa-phone';
                            else if (typeSelect.value === 'URL') icon = 'fa-external-link-alt';

                            const btnHtml = `
                                <div class="whatsapp-btn">
                                    <i class="fas ${icon} text-muted"></i>
                                    ${textInput.value}
                                </div>
                            `;
                            previewButtons.insertAdjacentHTML('beforeend', btnHtml);
                        }
                    });
                }
            }
        }

        // Attach listeners
        [nameInput, langInput, headerInput, bodyInput, footerInput].forEach(el => {
            if (el) el.addEventListener('input', updatePreview);
        });

        // Delegate listener for buttons dynamic additions/deletions
        if (buttonsContainer) {
            buttonsContainer.addEventListener('input', updatePreview);
        }

        // Run initially
        updatePreview();
    }

    // Dynamic Button Row Management in Templates
    const addBtn = document.getElementById('addBtnRow');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            const wrapper = document.getElementById('buttonsWrapper');
            const rowCount = wrapper.querySelectorAll('.button-row').length;
            if (rowCount >= 3) {
                Swal.fire('Limit Reached', 'WhatsApp templates allow a maximum of 3 buttons.', 'warning');
                return;
            }

            const html = `
                <div class="row g-2 mb-2 button-row align-items-center">
                    <div class="col-sm-4">
                        <select class="form-select btn-type" name="buttons[${rowCount}][type]">
                            <option value="QUICK_REPLY">Quick Reply</option>
                            <option value="PHONE">Call Phone Number</option>
                            <option value="URL">Visit Website URL</option>
                        </select>
                    </div>
                    <div class="col-sm-7">
                        <input type="text" class="form-control btn-text" name="buttons[${rowCount}][text]" placeholder="Button Label" required>
                        <div class="extra-field mt-1 d-none">
                            <input type="text" class="form-control form-control-sm btn-value" name="buttons[${rowCount}][value]" placeholder="Phone number (with country code) or URL">
                        </div>
                    </div>
                    <div class="col-sm-1 text-end">
                        <button type="button" class="btn btn-outline-danger btn-sm remove-btn-row">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            wrapper.insertAdjacentHTML('beforeend', html);
            if (typeof templateForm !== 'undefined' && templateForm) {
                // Trigger preview refresh
                templateForm.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        document.getElementById('buttonsWrapper').addEventListener('click', function(e) {
            if (e.target.closest('.remove-btn-row')) {
                e.target.closest('.button-row').remove();
                // Rename array keys to maintain order
                const wrapper = document.getElementById('buttonsWrapper');
                wrapper.querySelectorAll('.button-row').forEach((row, index) => {
                    row.querySelector('.btn-type').name = `buttons[${index}][type]`;
                    row.querySelector('.btn-text').name = `buttons[${index}][text]`;
                    const extra = row.querySelector('.btn-value');
                    if (extra) extra.name = `buttons[${index}][value]`;
                });
                
                // Trigger preview update
                const pBody = document.getElementById('previewBody');
                if (pBody) {
                    pBody.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        });

        document.getElementById('buttonsWrapper').addEventListener('change', function(e) {
            if (e.target.classList.contains('btn-type')) {
                const row = e.target.closest('.button-row');
                const extra = row.querySelector('.extra-field');
                const btnValueInput = row.querySelector('.btn-value');
                if (e.target.value === 'PHONE') {
                    extra.classList.remove('d-none');
                    btnValueInput.placeholder = 'Phone number (+919876543210)';
                    btnValueInput.required = true;
                } else if (e.target.value === 'URL') {
                    extra.classList.remove('d-none');
                    btnValueInput.placeholder = 'Website URL (https://example.com)';
                    btnValueInput.required = true;
                } else {
                    extra.classList.add('d-none');
                    btnValueInput.required = false;
                    btnValueInput.value = '';
                }
            }
        });
    }

    // -------------------------------------------------------------
    // 2. Real-time AJAX Bulk Sender Queue Processing
    // -------------------------------------------------------------
    window.activeCampaignSends = {};

    window.startCampaignSending = function (campaignId) {
        if (window.activeCampaignSends[campaignId]) {
            return; // Already running
        }

        window.activeCampaignSends[campaignId] = true;
        
        // Disable other interactions or show sending spinner indicator on row
        const row = document.getElementById(`campaign-row-${campaignId}`);
        if (row) {
            const startBtn = row.querySelector('.btn-start');
            const pauseBtn = row.querySelector('.btn-pause');
            if (startBtn) startBtn.classList.add('d-none');
            if (pauseBtn) pauseBtn.classList.remove('d-none');
            const badge = row.querySelector('.campaign-status-badge');
            if (badge) {
                badge.className = 'badge bg-warning text-dark campaign-status-badge';
                badge.textContent = 'Sending';
            }
        }

        processSendBatch(campaignId);
    };

    window.pauseCampaignSending = function (campaignId) {
        if (!window.activeCampaignSends[campaignId]) {
            return;
        }
        delete window.activeCampaignSends[campaignId];

        // Call API to update campaign status to Paused
        fetch(`api/campaign-actions.php?action=pause&campaign_id=${campaignId}`, { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const row = document.getElementById(`campaign-row-${campaignId}`);
                    if (row) {
                        const startBtn = row.querySelector('.btn-start');
                        const pauseBtn = row.querySelector('.btn-pause');
                        if (startBtn) startBtn.classList.remove('d-none');
                        if (pauseBtn) pauseBtn.classList.add('d-none');
                        const badge = row.querySelector('.campaign-status-badge');
                        if (badge) {
                            badge.className = 'badge bg-secondary campaign-status-badge';
                            badge.textContent = 'Paused';
                        }
                    }
                    Swal.fire({
                        title: 'Campaign Paused',
                        text: 'Queue processing has been paused successfully.',
                        icon: 'info',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000
                    });
                }
            });
    };

    function processSendBatch(campaignId) {
        if (!window.activeCampaignSends[campaignId]) {
            return; // Terminated/Paused
        }

        // Call backend API to process next batch
        fetch(`api/campaign-actions.php?action=send_batch&campaign_id=${campaignId}`, { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    // Critical API or Server error
                    delete window.activeCampaignSends[campaignId];

                    if (data.error && data.error.includes('not in active sending state')) {
                        const row = document.getElementById(`campaign-row-${campaignId}`);
                        if (row) {
                            const startBtn = row.querySelector('.btn-start');
                            const pauseBtn = row.querySelector('.btn-pause');
                            if (startBtn) startBtn.classList.remove('d-none');
                            if (pauseBtn) pauseBtn.classList.add('d-none');
                            const badge = row.querySelector('.campaign-status-badge');
                            if (badge) {
                                badge.className = 'badge bg-success campaign-status-badge';
                                badge.textContent = 'Completed';
                            }
                        }
                        return;
                    }

                    Swal.fire('Error', data.error || 'Failed to process batch', 'error');
                    // Reset UI
                    const row = document.getElementById(`campaign-row-${campaignId}`);
                    if (row) {
                        const startBtn = row.querySelector('.btn-start');
                        const pauseBtn = row.querySelector('.btn-pause');
                        if (startBtn) startBtn.classList.remove('d-none');
                        if (pauseBtn) pauseBtn.classList.add('d-none');
                    }
                    return;
                }

                // Update UI Progress Bar & Counts
                updateCampaignRowUI(campaignId, data.stats);

                if (data.completed) {
                    // Campaign completed sending
                    delete window.activeCampaignSends[campaignId];
                    
                    const row = document.getElementById(`campaign-row-${campaignId}`);
                    if (row) {
                        const startBtn = row.querySelector('.btn-start');
                        const pauseBtn = row.querySelector('.btn-pause');
                        const retryBtn = row.querySelector('.btn-retry-failed');
                        if (startBtn) startBtn.classList.add('d-none');
                        if (pauseBtn) pauseBtn.classList.add('d-none');
                        if (data.stats.Failed > 0 && retryBtn) {
                            retryBtn.classList.remove('d-none');
                        }
                        const badge = row.querySelector('.campaign-status-badge');
                        if (badge) {
                            badge.className = 'badge bg-success campaign-status-badge';
                            badge.textContent = 'Completed';
                        }
                    }
                    
                    Swal.fire({
                        title: 'Completed!',
                        text: `Campaign "${data.campaign_name}" has finished sending all messages.`,
                        icon: 'success',
                        confirmButtonColor: '#0d6efd'
                    });
                } else {
                    // Wait for 2 seconds (Delay requested in parameters) and call next batch
                    setTimeout(() => {
                        processSendBatch(campaignId);
                    }, 2000);
                }
            })
            .catch(err => {
                console.error(err);
                delete window.activeCampaignSends[campaignId];
                Swal.fire('Connection Error', 'Failed to reach API server. Pausing campaign.', 'error');
                // Reset UI
                const row = document.getElementById(`campaign-row-${campaignId}`);
                if (row) {
                    const startBtn = row.querySelector('.btn-start');
                    const pauseBtn = row.querySelector('.btn-pause');
                    if (startBtn) startBtn.classList.remove('d-none');
                    if (pauseBtn) pauseBtn.classList.add('d-none');
                }
            });
    }

    function updateCampaignRowUI(campaignId, stats) {
        const row = document.getElementById(`campaign-row-${campaignId}`);
        if (!row) return;

        const total = parseInt(row.dataset.total || '0');
        const processed = stats.Sent + stats.Delivered + stats.Read + stats.Failed;
        
        // Calculate progress percentage
        const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

        // Progress bar update
        const progressBar = row.querySelector('.progress-bar');
        if (progressBar) {
            progressBar.style.width = `${pct}%`;
            progressBar.textContent = `${pct}%`;
            progressBar.setAttribute('aria-valuenow', pct);
        }

        // Stats counts update
        const spanSent = row.querySelector('.stat-sent');
        const spanDelivered = row.querySelector('.stat-delivered');
        const spanFailed = row.querySelector('.stat-failed');

        if (spanSent) spanSent.textContent = stats.Sent;
        if (spanDelivered) spanDelivered.textContent = stats.Delivered + stats.Read;
        if (spanFailed) spanFailed.textContent = stats.Failed;
    }

    // -------------------------------------------------------------
    // 3. Contact List Multi-select and Bulk Delete Logic
    // -------------------------------------------------------------
    const selectAllCheckbox = document.getElementById('selectAllContacts');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            const checkboxes = document.querySelectorAll('.contact-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = selectAllCheckbox.checked;
            });
            toggleBulkDeleteBtn();
        });

        // Individual checkbox change delegates to parent container
        const contactsTable = document.getElementById('contactsTable');
        if (contactsTable) {
            contactsTable.addEventListener('change', function (e) {
                if (e.target.classList.contains('contact-checkbox')) {
                    toggleBulkDeleteBtn();
                }
            });
        }
    }

    function toggleBulkDeleteBtn() {
        const bulkDeleteBtn = document.getElementById('btnBulkDelete');
        if (bulkDeleteBtn) {
            const checkedCount = document.querySelectorAll('.contact-checkbox:checked').length;
            if (checkedCount > 0) {
                bulkDeleteBtn.classList.remove('d-none');
                bulkDeleteBtn.innerHTML = `<i class="fas fa-trash-alt me-1"></i> Delete Selected (${checkedCount})`;
            } else {
                bulkDeleteBtn.classList.add('d-none');
            }
        }
    }

    const bulkDeleteForm = document.getElementById('bulkDeleteForm');
    if (bulkDeleteForm) {
        bulkDeleteForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const checkedBoxes = document.querySelectorAll('.contact-checkbox:checked');
            if (checkedBoxes.length === 0) return;

            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to delete ${checkedBoxes.length} selected contacts. This action is irreversible!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete them!'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Populate hidden input
                    const idsInput = document.getElementById('bulkDeleteIds');
                    const ids = Array.from(checkedBoxes).map(cb => cb.value);
                    idsInput.value = ids.join(',');
                    bulkDeleteForm.submit();
                }
            });
        });
    }

    // -------------------------------------------------------------
    // 4. Test Meta Connection Helper
    // -------------------------------------------------------------
    const btnTestConn = document.getElementById('btnTestConnection');
    if (btnTestConn) {
        btnTestConn.addEventListener('click', function () {
            Swal.fire({
                title: 'Test WhatsApp Connection',
                text: 'Enter a valid WhatsApp number (with country code, no + or 00) to receive a test message:',
                input: 'text',
                inputPlaceholder: 'e.g. 919876543210',
                showCancelButton: true,
                confirmButtonText: 'Send Test Template',
                confirmButtonColor: '#0d6efd',
                showLoaderOnConfirm: true,
                preConfirm: (phone) => {
                    if (!phone) {
                        Swal.showValidationMessage('Phone number is required');
                        return;
                    }
                    // Clean phone number format
                    const cleanPhone = phone.replace(/[^0-9]/g, '').replace(/^0+/, '');
                    if (cleanPhone.length < 10) {
                        Swal.showValidationMessage('Invalid phone number format');
                        return;
                    }

                    // Retrieve currently filled form values to test without saving
                    const form = document.getElementById('settingsForm');
                    const formData = new FormData(form);
                    formData.append('test_phone', cleanPhone);

                    return fetch('api/campaign-actions.php?action=test_connection', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(response.statusText);
                        }
                        return response.json();
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`Request failed: ${error}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    const data = result.value;
                    if (data.success) {
                        Swal.fire('Success!', 'Test message dispatched successfully. Check your WhatsApp!', 'success');
                    } else {
                        Swal.fire('Failed', `API Error: ${data.error || 'Unknown error'}`, 'error');
                    }
                }
            });
        });
    }

    const btnValidateWaba = document.getElementById('btnValidateWaba');
    if (btnValidateWaba) {
        btnValidateWaba.addEventListener('click', function () {
            Swal.fire({
                title: 'Validate WABA ID',
                text: 'This will verify whether the entered Business Account ID is a valid WhatsApp Business Account ID.',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Validate Now',
                confirmButtonColor: '#0d6efd',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    const form = document.getElementById('settingsForm');
                    const formData = new FormData(form);
                    return fetch('api/campaign-actions.php?action=verify_waba', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(response.statusText);
                        }
                        return response.json();
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`Request failed: ${error}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    const data = result.value;
                    if (data.success) {
                        Swal.fire('Valid WABA ID', `Verified WABA ID ${data.account.id} (${data.account.name || 'Unnamed'})`, 'success');
                    } else {
                        Swal.fire('Validation Failed', `API Error: ${data.error || 'Unknown error'}`, 'error');
                    }
                }
            });
        });
    }
});
