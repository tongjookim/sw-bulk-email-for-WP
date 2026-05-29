/* global swBulkEmail, tinymce, jQuery */
jQuery(document).ready(function ($) {
	'use strict';

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/** Get content from wp_editor (TinyMCE visual or plain textarea fallback). */
	function getEditorContent(editorId) {
		if (typeof tinymce !== 'undefined') {
			var ed = tinymce.get(editorId);
			if (ed && !ed.isHidden()) {
				return ed.getContent();
			}
		}
		return $('#' + editorId).val() || '';
	}

	/** Set content in wp_editor (TinyMCE visual or plain textarea fallback). */
	function setEditorContent(editorId, content) {
		if (typeof tinymce !== 'undefined') {
			var ed = tinymce.get(editorId);
			if (ed) {
				ed.setContent(content);
				return;
			}
		}
		$('#' + editorId).val(content);
	}

	/** Update the progress bar and label. */
	function updateProgress($bar, $label, processed, total, sent, failed) {
		var pct = total > 0 ? Math.round((processed / total) * 100) : 0;
		$bar.val(pct);
		$label.text(
			swBulkEmail.i18n.progress
				.replace('{processed}', processed)
				.replace('{total}',     total)
				.replace('{sent}',      sent)
				.replace('{failed}',    failed)
		);
	}

	/** Render a WP-style admin notice inside a target element. */
	function setStatus(selector, type, msg) {
		var cls = (type === 'success') ? 'notice-success' : 'notice-error';
		$(selector).html(
			'<div class="notice ' + cls + ' inline" style="margin:0;"><p>' + msg + '</p></div>'
		);
	}

	// -----------------------------------------------------------------------
	// Templates
	// -----------------------------------------------------------------------

	var tab = swBulkEmail.activeTab; // 'subscriber' or 'system'
	var pfx = (tab === 'subscriber') ? 'sub' : 'sys';

	$('#sw-' + pfx + '-load-btn').on('click', function() {
		var tplId = $('#sw-' + pfx + '-templates').val();
		if (!tplId) { return; }

		$.post(swBulkEmail.ajaxUrl, {
			action: 'sw_load_template',
			nonce:  swBulkEmail.nonce,
			id:     tplId
		}, function(resp) {
			if (resp.success) {
				$('#sw-' + pfx + '-subject').val(resp.data.subject);
				setEditorContent('sw_' + pfx + '_body', resp.data.body);
				setStatus('#sw-' + pfx + '-status', 'success', swBulkEmail.i18n.templateLoaded);
			}
		});
	});

	$('#sw-' + pfx + '-save-btn').on('click', function() {
		var subject = $('#sw-' + pfx + '-subject').val().trim();
		var body = getEditorContent('sw_' + pfx + '_body');
		var name = prompt(swBulkEmail.i18n.promptTemplateName);

		if (!name || !subject || !body) {
			alert(swBulkEmail.i18n.noContent);
			return;
		}

		$.post(swBulkEmail.ajaxUrl, {
			action:  'sw_save_template',
			nonce:   swBulkEmail.nonce,
			name:    name,
			subject: subject,
			body:    body,
			type:    tab
		}, function(resp) {
			if (resp.success) {
				$('#sw-' + pfx + '-templates').append(
					$('<option>', { value: resp.data.id, text: resp.data.name })
				);
				setStatus('#sw-' + pfx + '-status', 'success', swBulkEmail.i18n.templateSaved);
			}
		});
	});

	$('#sw-' + pfx + '-delete-btn').on('click', function() {
		var $select = $('#sw-' + pfx + '-templates');
		var tplId = $select.val();
		if (!tplId) { return; }

		if (!confirm(swBulkEmail.i18n.confirmDelete)) {
			return;
		}

		$.post(swBulkEmail.ajaxUrl, {
			action: 'sw_delete_template',
			nonce:  swBulkEmail.nonce,
			id:     tplId
		}, function(resp) {
			if (resp.success) {
				$select.find('option:selected').remove();
				setStatus('#sw-' + pfx + '-status', 'success', swBulkEmail.i18n.templateDeleted);
			}
		});
	});


	// -----------------------------------------------------------------------
	// Archive helpers
	// -----------------------------------------------------------------------

	/**
	 * Save an archive entry before sending. Calls callback(archiveId) on success.
	 */
	function swArchiveSave(subject, body, mailType, callback, status) {
		$.post(swBulkEmail.ajaxUrl, {
			action:    'sw_archive_save',
			nonce:     swBulkEmail.nonce,
			subject:   subject,
			body:      body,
			mail_type: mailType,
			status:    status || 'sent'
		}, function(resp) {
			if (resp.success && resp.data && resp.data.archive_id) {
				callback(resp.data.archive_id);
			} else {
				callback(0); // fail gracefully – send continues without archive id
			}
		}).fail(function() {
			callback(0);
		});
	}

	/**
	 * Save a draft (no send). Shows notice with archive link on success.
	 */
	function saveDraft(subject, body, mailType, $btn, statusSelector) {
		if (!subject || !body) {
			alert(swBulkEmail.i18n.noContent);
			return;
		}
		$btn.prop('disabled', true).text(swBulkEmail.i18n.saving);

		swArchiveSave(subject, body, mailType, function(archiveId) {
			$btn.prop('disabled', false).text(swBulkEmail.i18n.saveDraft);
			if (archiveId) {
				var link = '<a href="' + swBulkEmail.archiveUrl + '">' +
					'발송 내역에서 확인</a>';
				setStatus(statusSelector, 'success',
					swBulkEmail.i18n.draftSaved + ' ' + link);
			} else {
				setStatus(statusSelector, 'error', swBulkEmail.i18n.draftSaveFail);
			}
		}, 'draft');
	}

	/**
	 * Update archive stats after batch completes.
	 */
	function swArchiveFinish(archiveId, sent, failed) {
		if (!archiveId) { return; }
		$.post(swBulkEmail.ajaxUrl, {
			action:       'sw_archive_finish',
			nonce:        swBulkEmail.nonce,
			archive_id:   archiveId,
			sent_count:   sent,
			failed_count: failed
		});
	}

	// -----------------------------------------------------------------------
	// Subscriber mail  (tab=subscriber)
	// -----------------------------------------------------------------------

	$('#sw-sub-draft-btn').on('click', function() {
		saveDraft(
			$('#sw-sub-subject').val().trim(),
			getEditorContent('sw_sub_body'),
			'subscriber',
			$(this),
			'#sw-sub-status'
		);
	});

	var $subSendBtn = $('#sw-sub-send-btn');

	function sendSubscriberBatch(subject, body, offset, sent, failed, archiveId) {
		$.post(
			swBulkEmail.ajaxUrl,
			{
				action:     'sw_send_batch',
				nonce:      swBulkEmail.nonce,
				subject:    subject,
				body:       body,
				offset:     offset,
				batch_size: swBulkEmail.batchSize
			},
			function (resp) {
				if (!resp.success) {
					setStatus('#sw-sub-status', 'error',
						resp.data ? resp.data.message : swBulkEmail.i18n.error);
					$subSendBtn.prop('disabled', false).text(swBulkEmail.i18n.sendSubscriber);
					return;
				}

				var d = resp.data;
				sent   += d.sent;
				failed += d.failed;
				updateProgress(
					$('#sw-sub-progress-bar'),
					$('#sw-sub-progress-label'),
					d.processed, d.total, sent, failed
				);

				if (d.done) {
					swArchiveFinish(archiveId, sent, failed);
					setStatus('#sw-sub-status', 'success',
						swBulkEmail.i18n.done +
						' — 성공: ' + sent + '건 / 실패: ' + failed + '건');
					$subSendBtn.prop('disabled', false).text(swBulkEmail.i18n.sendSubscriber);
					$('#sw-sub-progress-wrap').slideUp(300);
				} else {
					sendSubscriberBatch(subject, body, d.processed, sent, failed, archiveId);
				}
			}
		).fail(function () {
			setStatus('#sw-sub-status', 'error', swBulkEmail.i18n.error);
			$subSendBtn.prop('disabled', false).text(swBulkEmail.i18n.sendSubscriber);
		});
	}

	$subSendBtn.on('click', function () {
		var subject = $('#sw-sub-subject').val().trim();
		var body    = getEditorContent('sw_sub_body');

		if (!subject || !body) {
			alert(swBulkEmail.i18n.noContent);
			return;
		}
		if (!confirm(swBulkEmail.i18n.confirmSubscriber)) {
			return;
		}

		$(this).prop('disabled', true).text(swBulkEmail.i18n.sending);
		$('#sw-sub-status').html('');
		$('#sw-sub-progress-wrap').show();
		$('#sw-sub-progress-bar').val(0);
		$('#sw-sub-progress-label').text('');

		swArchiveSave(subject, body, 'subscriber', function(archiveId) {
			sendSubscriberBatch(subject, body, 0, 0, 0, archiveId);
		});
	});

	// -----------------------------------------------------------------------
	// System mail  (tab=system)
	// -----------------------------------------------------------------------

	$('#sw-sys-draft-btn').on('click', function() {
		saveDraft(
			$('#sw-sys-subject').val().trim(),
			getEditorContent('sw_sys_body'),
			'system',
			$(this),
			'#sw-sys-status'
		);
	});

	// --- Preview ---

	$('#sw-sys-preview-btn').on('click', function () {
		var subject = $('#sw-sys-subject').val().trim();
		var body    = getEditorContent('sw_sys_body');

		if (!body) {
			alert(swBulkEmail.i18n.noContent);
			return;
		}

		var info = swBulkEmail.senderInfo;
		$('#sw-preview-subject').text(subject || '(제목 없음)');

		// Write rendered HTML into the sandboxed iframe.
		var iframe = document.getElementById('sw-preview-iframe');
		var doc    = iframe.contentDocument || iframe.contentWindow.document;
		doc.open();
		doc.write(
			'<!DOCTYPE html><html><head><meta charset="utf-8">' +
			'<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
			'max-width:600px;margin:0 auto;padding:24px;color:#333;line-height:1.6;}' +
			'img{max-width:100%;}</style></head><body>' +
			body +
			'</body></html>'
		);
		doc.close();

		$('#sw-preview-modal').fadeIn(180);
		$('#sw-preview-close').trigger('focus');
	});

	$('#sw-preview-close, #sw-preview-overlay').on('click', function () {
		$('#sw-preview-modal').fadeOut(150);
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && $('#sw-preview-modal').is(':visible')) {
			$('#sw-preview-modal').fadeOut(150);
		}
	});

	// --- Test send ---

	var $testBtn = $('#sw-sys-test-btn');

	$testBtn.on('click', function () {
		var subject = $('#sw-sys-subject').val().trim();
		var body    = getEditorContent('sw_sys_body');

		if (!subject || !body) {
			alert(swBulkEmail.i18n.noContent);
			return;
		}
		$(this).prop('disabled', true).text(swBulkEmail.i18n.sending);

		$.post(
			swBulkEmail.ajaxUrl,
			{
				action:  'sw_test_send',
				nonce:   swBulkEmail.nonce,
				subject: subject,
				body:    body
			},
			function (resp) {
				$testBtn.prop('disabled', false).text(swBulkEmail.i18n.testSend);
				if (resp.success) {
					setStatus('#sw-sys-status', 'success', resp.data.message);
				} else {
					setStatus('#sw-sys-status', 'error',
						resp.data ? resp.data.message : swBulkEmail.i18n.error);
				}
			}
		).fail(function () {
			$testBtn.prop('disabled', false).text(swBulkEmail.i18n.testSend);
			setStatus('#sw-sys-status', 'error', swBulkEmail.i18n.error);
		});
	});

	// --- System batch send ---

	// -----------------------------------------------------------------------
	// Ad mail (tab=ad)
	// -----------------------------------------------------------------------

	$('#sw-ad-draft-btn').on('click', function() {
		saveDraft(
			$('#sw-ad-subject').val().trim(),
			getEditorContent('sw_ad_body'),
			'ad',
			$(this),
			'#sw-ad-status'
		);
	});

	var $adSendBtn = $('#sw-ad-send-btn');

	function sendAdBatch(subject, body, offset, sent, failed, archiveId) {
		$.post(
			swBulkEmail.ajaxUrl,
			{
				action:     'sw_send_ad_batch',
				nonce:      swBulkEmail.nonce,
				subject:    subject,
				body:       body,
				offset:     offset,
				batch_size: swBulkEmail.batchSize
			},
			function (resp) {
				if (!resp.success) {
					setStatus('#sw-ad-status', 'error',
						resp.data ? resp.data.message : swBulkEmail.i18n.error);
					$adSendBtn.prop('disabled', false).text(swBulkEmail.i18n.sendAd);
					return;
				}

				var d = resp.data;
				sent   += d.sent;
				failed += d.failed;
				updateProgress(
					$('#sw-ad-progress-bar'),
					$('#sw-ad-progress-label'),
					d.processed, d.total, sent, failed
				);

				if (d.done) {
					swArchiveFinish(archiveId, sent, failed);
					setStatus('#sw-ad-status', 'success',
						swBulkEmail.i18n.done +
						' — 성공: ' + sent + '건 / 실패: ' + failed + '건');
					$adSendBtn.prop('disabled', false).text(swBulkEmail.i18n.sendAd);
					$('#sw-ad-progress-wrap').slideUp(300);
				} else {
					sendAdBatch(subject, body, d.processed, sent, failed, archiveId);
				}
			}
		).fail(function () {
			setStatus('#sw-ad-status', 'error', swBulkEmail.i18n.error);
			$adSendBtn.prop('disabled', false).text(swBulkEmail.i18n.sendAd);
		});
	}

	$adSendBtn.on('click', function () {
		var subject = $('#sw-ad-subject').val().trim();
		var body    = getEditorContent('sw_ad_body');

		if (!subject || !body) {
			alert(swBulkEmail.i18n.noContent);
			return;
		}
		if (!confirm(swBulkEmail.i18n.confirmAd)) {
			return;
		}

		$(this).prop('disabled', true).text(swBulkEmail.i18n.sending);
		$('#sw-ad-status').html('');
		$('#sw-ad-progress-wrap').show();
		$('#sw-ad-progress-bar').val(0);
		$('#sw-ad-progress-label').text('');

		swArchiveSave(subject, body, 'ad', function(archiveId) {
			sendAdBatch(subject, body, 0, 0, 0, archiveId);
		});
	});

	var $sysSendBtn = $('#sw-sys-send-btn');

	function sendSystemBatch(subject, body, offset, sent, failed, archiveId) {
		$.post(
			swBulkEmail.ajaxUrl,
			{
				action:     'sw_send_system_batch',
				nonce:      swBulkEmail.nonce,
				subject:    subject,
				body:       body,
				offset:     offset,
				batch_size: swBulkEmail.batchSize
			},
			function (resp) {
				if (!resp.success) {
					setStatus('#sw-sys-status', 'error',
						resp.data ? resp.data.message : swBulkEmail.i18n.error);
					$sysSendBtn.prop('disabled', false).text(swBulkEmail.i18n.sendSystem);
					return;
				}

				var d = resp.data;
				sent   += d.sent;
				failed += d.failed;
				updateProgress(
					$('#sw-sys-progress-bar'),
					$('#sw-sys-progress-label'),
					d.processed, d.total, sent, failed
				);

				if (d.done) {
					swArchiveFinish(archiveId, sent, failed);
					setStatus('#sw-sys-status', 'success',
						swBulkEmail.i18n.done +
						' — 성공: ' + sent + '건 / 실패: ' + failed + '건');
					$sysSendBtn.prop('disabled', false).text(swBulkEmail.i18n.sendSystem);
					$('#sw-sys-progress-wrap').slideUp(300);
				} else {
					sendSystemBatch(subject, body, d.processed, sent, failed, archiveId);
				}
			}
		).fail(function () {
			setStatus('#sw-sys-status', 'error', swBulkEmail.i18n.error);
			$sysSendBtn.prop('disabled', false).text(swBulkEmail.i18n.sendSystem);
		});
	}

	$sysSendBtn.on('click', function () {
		var subject = $('#sw-sys-subject').val().trim();
		var body    = getEditorContent('sw_sys_body');

		if (!subject || !body) {
			alert(swBulkEmail.i18n.noContent);
			return;
		}
		if (!confirm(swBulkEmail.i18n.confirmSystem)) {
			return;
		}

		$(this).prop('disabled', true).text(swBulkEmail.i18n.sending);
		$('#sw-sys-status').html('');
		$('#sw-sys-progress-wrap').show();
		$('#sw-sys-progress-bar').val(0);
		$('#sw-sys-progress-label').text('');

		swArchiveSave(subject, body, 'system', function(archiveId) {
			sendSystemBatch(subject, body, 0, 0, 0, archiveId);
		});
	});

	// -----------------------------------------------------------------------
	// Archive edit page – save content
	// -----------------------------------------------------------------------

	$('#sw-archive-save-content-btn').on('click', function() {
		var id      = $(this).data('id');
		var subject = $('#sw-archive-subject').val().trim();
		var body    = getEditorContent('sw_archive_body');

		if (!subject || !body) {
			alert(swBulkEmail.i18n.noContent);
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true).text('저장 중…');

		$.post(swBulkEmail.ajaxUrl, {
			action:  'sw_archive_update',
			nonce:   swBulkEmail.nonce,
			id:      id,
			subject: subject,
			body:    body
		}, function(resp) {
			$btn.prop('disabled', false).text('수정 내용 저장');
			if (resp.success) {
				setStatus('#sw-archive-edit-notice', 'success', resp.data.message || '저장되었습니다.');
			} else {
				setStatus('#sw-archive-edit-notice', 'error',
					(resp.data && resp.data.message) ? resp.data.message : '저장 실패');
			}
		}).fail(function() {
			$btn.prop('disabled', false).text('수정 내용 저장');
			setStatus('#sw-archive-edit-notice', 'error', swBulkEmail.i18n.error);
		});
	});

	// -----------------------------------------------------------------------
	// Archive edit page – resend buttons
	// -----------------------------------------------------------------------

	function getArchiveResendData() {
		return {
			subject: $('#sw-archive-subject').val().trim(),
			body:    getEditorContent('sw_archive_body')
		};
	}

	function archiveResendBatch(action, subject, body, offset, sent, failed, archiveId, confirmSentLabel) {
		$.post(swBulkEmail.ajaxUrl, {
			action:     action,
			nonce:      swBulkEmail.nonce,
			subject:    subject,
			body:       body,
			offset:     offset,
			batch_size: swBulkEmail.batchSize
		}, function(resp) {
			if (!resp.success) {
				setStatus('#sw-archive-resend-status', 'error',
					resp.data ? resp.data.message : swBulkEmail.i18n.error);
				$('#sw-archive-send-sub-btn, #sw-archive-send-ad-btn, #sw-archive-send-sys-btn')
					.prop('disabled', false);
				return;
			}
			var d = resp.data;
			sent   += d.sent;
			failed += d.failed;
			updateProgress(
				$('#sw-archive-resend-progress-bar'),
				$('#sw-archive-resend-progress-label'),
				d.processed, d.total, sent, failed
			);
			if (d.done) {
				swArchiveFinish(archiveId, sent, failed);
				$('#sw-archive-stat-sent').text(sent);
				$('#sw-archive-stat-failed').text(failed);
				setStatus('#sw-archive-resend-status', 'success',
					swBulkEmail.i18n.done + ' — 성공: ' + sent + '건 / 실패: ' + failed + '건');
				$('#sw-archive-send-sub-btn, #sw-archive-send-ad-btn, #sw-archive-send-sys-btn')
					.prop('disabled', false);
				$('#sw-archive-resend-progress-wrap').slideUp(300);
			} else {
				archiveResendBatch(action, subject, body, d.processed, sent, failed, archiveId, confirmSentLabel);
			}
		}).fail(function() {
			setStatus('#sw-archive-resend-status', 'error', swBulkEmail.i18n.error);
			$('#sw-archive-send-sub-btn, #sw-archive-send-ad-btn, #sw-archive-send-sys-btn')
				.prop('disabled', false);
		});
	}

	/**
	 * Start a send from the archive edit page.
	 * Draft items reuse the existing archive ID (status → sent).
	 * Non-draft (resend) items create a new archive entry.
	 */
	function startArchiveSend(batchAction, mailType, confirmMsg) {
		var data     = getArchiveResendData();
		var $btn     = $('#sw-archive-send-sub-btn, #sw-archive-send-ad-btn, #sw-archive-send-sys-btn');
		var $trigger = $('#sw-archive-send-sub-btn[data-mail-type]').length
			? $('#sw-archive-send-sub-btn') : $btn.first();

		// Read id/status from whichever send button triggered this.
		var id       = parseInt($('#sw-archive-send-sub-btn').data('id')   || 0, 10);
		var status   = $('#sw-archive-send-sub-btn').data('status') || 'sent';
		var isDraft  = (status === 'draft');

		if (!data.subject || !data.body) { alert(swBulkEmail.i18n.noContent); return false; }
		if (!confirm(confirmMsg)) { return false; }

		$btn.prop('disabled', true);
		$('#sw-archive-resend-status').html('');
		$('#sw-archive-resend-progress-wrap').show();

		if (isDraft) {
			// Mark draft as sent, then batch using the same archive ID.
			$.post(swBulkEmail.ajaxUrl, {
				action: 'sw_archive_update_status',
				nonce:  swBulkEmail.nonce,
				id:     id,
				status: 'sent'
			}, function() {
				// Update button labels to reflect sent state.
				$('#sw-archive-send-sub-btn').data('status', 'sent');
				$('#sw-archive-send-ad-btn').data('status', 'sent');
				$('#sw-archive-send-sys-btn').data('status', 'sent');
				archiveResendBatch(batchAction, data.subject, data.body, 0, 0, 0, id, mailType);
			}).fail(function() {
				$btn.prop('disabled', false);
				setStatus('#sw-archive-resend-status', 'error', swBulkEmail.i18n.error);
			});
		} else {
			// Resend: create a new archive entry.
			swArchiveSave(data.subject, data.body, mailType, function(archiveId) {
				archiveResendBatch(batchAction, data.subject, data.body, 0, 0, 0,
					archiveId || id, mailType);
			});
		}
		return true;
	}

	$('#sw-archive-send-sub-btn').on('click', function() {
		startArchiveSend('sw_send_batch', 'subscriber', swBulkEmail.i18n.confirmSubscriber);
	});

	$('#sw-archive-send-ad-btn').on('click', function() {
		startArchiveSend('sw_send_ad_batch', 'ad', swBulkEmail.i18n.confirmAd);
	});

	$('#sw-archive-send-sys-btn').on('click', function() {
		startArchiveSend('sw_send_system_batch', 'system', swBulkEmail.i18n.confirmSystem);
	});
});
