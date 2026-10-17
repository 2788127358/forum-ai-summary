/* ==========================================================================
   论坛 AI 摘要 · 后台脚本（v2.1.0 · Editorial）
   --------------------------------------------------------------------------
   零依赖，只用 WP 自带 jQuery。四件事：
     1. 页签切换 + 墨条滑动
     2. 开关 ON/OFF 文字同步
     3. 帖子编辑页 Meta Box 单篇生成
     4. 「内容」页签的存量补齐循环
   ========================================================================== */
(function ($) {
	'use strict';

	$(function () {

		/* ---------------------------------------------------------------
		   1. 页签 + 墨条
		   --------------------------------------------------------------- */
		var $tabs = $('.fas-admin .fas-tab');
		var $ink = $('.fas-admin .fas-tab-ink');

		function moveInk($tab) {
			if (!$ink.length || !$tab.length) { return; }
			var left = $tab.position().left;
			var width = $tab.outerWidth();
			$ink.css({ width: width + 'px', transform: 'translateX(' + left + 'px)' });
		}

		function activateTab(name) {
			var $tab = $tabs.filter('[data-tab="' + name + '"]');
			if (!$tab.length) { return; }
			$tabs.removeClass('is-active').attr('aria-selected', 'false');
			$tab.addClass('is-active').attr('aria-selected', 'true');
			$('.fas-admin .fas-panel').attr('hidden', 'hidden');
			$('#fas-tab-' + name).removeAttr('hidden');
			moveInk($tab);
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, '', '#fas-tab-' + name);
			}
		}

		if ($tabs.length) {
			$tabs.on('click', function () { activateTab($(this).data('tab')); });

			var hash = (window.location.hash || '').replace('#fas-tab-', '');
			if (hash && $tabs.filter('[data-tab="' + hash + '"]').length) {
				activateTab(hash);
			} else {
				moveInk($tabs.filter('.is-active'));
			}

			$(window).on('resize', function () { moveInk($tabs.filter('.is-active')); });
		}

		/* ---------------------------------------------------------------
		   2. 开关文字
		   --------------------------------------------------------------- */
		$(document).on('change', '.fas-admin .fas-switch input', function () {
			$(this).closest('.fas-switch').find('.fas-switch-text').text(this.checked ? 'ON' : 'OFF');
		});

		/* ---------------------------------------------------------------
		   3. 单篇生成（帖子编辑页 Meta Box）
		   --------------------------------------------------------------- */
		function generateOne(postId, force, tagsOnly) {
			var dfd = $.Deferred();
			$.post(window.ajaxurl, {
				action: 'fas_generate',
				nonce: $('#fas_nonce').val(),
				post_id: postId,
				force: force ? 1 : 0,
				tags_only: tagsOnly ? 1 : 0
			}).done(function (res) { dfd.resolve(res); }).fail(function () { dfd.reject(); });
			return dfd.promise();
		}

		$(document).on('click', '.fas-generate-btn, .fas-generate-tags-btn', function () {
			var $btn = $(this);
			var postId = $btn.data('post-id');
			var tagsOnly = $btn.hasClass('fas-generate-tags-btn');
			var origin = $btn.text();

			$btn.prop('disabled', true).text('生成中…');
			generateOne(postId, true, tagsOnly).always(function () {
				$btn.prop('disabled', false).text(origin);
				window.location.reload();
			});
		});

		/* ---------------------------------------------------------------
		   4. 存量补齐
		   --------------------------------------------------------------- */
		(function initBackfill() {
			var $start = $('#fas-backfill-start');
			if (!$start.length) { return; }

			var $stop = $('#fas-backfill-stop');
			var $prog = $('#fas-backfill-progress');
			var $bar = $prog.find('i');
			var $log = $('#fas-backfill-log');
			var stopped = false, ok = 0, fail = 0, total = 0;

			$stop.on('click', function () {
				stopped = true;
				$stop.prop('disabled', true).text('停止中…');
			});

			$start.on('click', function () {
				var ids;
				try { ids = JSON.parse($start.attr('data-ids') || '[]'); } catch (e) { ids = []; }
				if (!ids.length) { return; }

				total = ids.length;
				stopped = false; ok = 0; fail = 0;

				$start.prop('disabled', true).text('补齐中…');
				$stop.prop('disabled', false).removeAttr('hidden');
				$prog.removeAttr('hidden');
				$log.text('已完成 0 / ' + total);

				var i = 0;

				function finish() {
					$start.prop('disabled', false).text('一键补齐（' + total + ' 篇）');
					$stop.attr('hidden', 'hidden').prop('disabled', false);
					var head = stopped ? '已停止' : '补齐完成';
					$log.text(head + '：共 ' + total + ' 篇，成功 ' + ok + '，失败 ' + fail +
						(fail ? '（失败项可再点一次重跑）' : '') + '。');
					if (!stopped && !fail) {
						window.setTimeout(function () { window.location.reload(); }, 1200);
					}
				}

				function step() {
					if (stopped || i >= total) { finish(); return; }
					generateOne(ids[i], false, false).always(function (res) {
						var code = res && res.data ? res.data.code : '';
						if (code === 'success' || code === 'skipped') { ok++; } else { fail++; }
						i++;
						$bar.css('width', Math.round((i / total) * 100) + '%');
						$log.text('已完成 ' + i + ' / ' + total + '（成功 ' + ok + '，失败 ' + fail + '）');
						window.setTimeout(step, 150);
					});
				}

				step();
			});
		})();

	});
})(jQuery);
