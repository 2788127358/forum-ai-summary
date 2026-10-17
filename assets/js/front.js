/* 论坛 AI 摘要 - 相关推荐：移动到评论上方 + 左右箭头切换 + 滚轮/拖拽/触摸横向滑动 */
(function () {
	'use strict';

	function moveRelated() {
		var wrap = document.querySelector('.fas-related');
		if (!wrap) {
			return;
		}

		// 目标位置：评论模块（#comments）之前，即「正文下方 / 评论上方」。
		var comments = document.getElementById('comments');
		if (comments && comments.parentNode) {
			comments.parentNode.insertBefore(wrap, comments);
			return;
		}

		// 兜底：评论关闭/不存在时，放到正文 article 之后。
		var article = document.querySelector('.forum-article');
		if (article && article.parentNode) {
			article.insertAdjacentElement('afterend', wrap);
		}
	}

	// 更新箭头可用态与右端渐变提示
	function updateNav(scroll) {
		var max = scroll.scrollWidth - scroll.clientWidth;
		var hasOverflow = max > 0;
		var wrap = scroll.parentElement;
		var prev = wrap ? wrap.querySelector('.fas-related-prev') : null;
		var next = wrap ? wrap.querySelector('.fas-related-next') : null;

		if (prev) {
			prev.classList.toggle('is-disabled', !hasOverflow || scroll.scrollLeft <= 0);
		}
		if (next) {
			next.classList.toggle('is-disabled', !hasOverflow || scroll.scrollLeft >= max - 4);
		}
		scroll.classList.toggle('is-end', scroll.scrollLeft >= max - 4);
	}

	function enhanceScroll(scroll) {
		if (!scroll) {
			return;
		}

		// 1) 鼠标滚轮：纵向滚动转为横向（到达两端时交还给页面纵向滚动）。
		scroll.addEventListener('wheel', function (e) {
			var max = scroll.scrollWidth - scroll.clientWidth;
			if (max <= 0) {
				return;
			}
			var delta = Math.abs(e.deltaY) > Math.abs(e.deltaX) ? e.deltaY : e.deltaX;
			if (Math.abs(delta) < 1) {
				return;
			}
			var atStart = scroll.scrollLeft <= 0 && delta < 0;
			var atEnd = scroll.scrollLeft >= max - 1 && delta > 0;
			if (atStart || atEnd) {
				return; // 让页面正常纵向滚动
			}
			e.preventDefault();
			scroll.scrollLeft += delta;
		}, { passive: false });

		// 2) 鼠标拖拽横向滚动（触屏设备走原生滑动，不做拦截）。
		var isDown = false;
		var moved = false;
		var startX = 0;
		var startScroll = 0;

		scroll.addEventListener('pointerdown', function (e) {
			if (e.pointerType !== 'mouse') {
				return;
			}
			isDown = true;
			moved = false;
			startX = e.clientX;
			startScroll = scroll.scrollLeft;
			scroll.classList.add('is-dragging');
			if (scroll.setPointerCapture) {
				scroll.setPointerCapture(e.pointerId);
			}
		});

		scroll.addEventListener('pointermove', function (e) {
			if (!isDown) {
				return;
			}
			var dx = e.clientX - startX;
			if (Math.abs(dx) > 3) {
				moved = true;
			}
			scroll.scrollLeft = startScroll - dx;
		});

		function endDrag() {
			if (!isDown) {
				return;
			}
			isDown = false;
			scroll.classList.remove('is-dragging');
		}
		scroll.addEventListener('pointerup', endDrag);
		scroll.addEventListener('pointercancel', endDrag);

		// 拖拽后吞掉一次 click，避免误触发卡片链接。
		scroll.addEventListener('click', function (e) {
			if (moved) {
				e.preventDefault();
				e.stopPropagation();
				moved = false;
			}
		}, true);

		// 3) 滚动位置反馈：更新箭头与右端渐变提示。
		scroll.addEventListener('scroll', function () {
			updateNav(scroll);
		}, { passive: true });
	}

	// 封面图懒加载：用 IntersectionObserver 监听横向滚动容器内的卡片图，
	// 滚动进可视区（含预载边距）才把 data-src 赋给 src，避免首屏一次性拉 8 张大图。
	function initLazy() {
		var imgs = document.querySelectorAll('.fas-related img.fas-lazy');
		if (!imgs.length) {
			return;
		}

		function load(img) {
			var src = img.getAttribute('data-src');
			if (!src || img.dataset.loaded === '1') {
				return;
			}
			img.dataset.loaded = '1';
			img.addEventListener('load', function () {
				img.classList.add('is-loaded');
			});
			img.src = src;
			if (img.complete) {
				img.classList.add('is-loaded');
			}
		}

		if (!('IntersectionObserver' in window)) {
			// 不支持时直接全部加载，保证可用性。
			imgs.forEach(load);
			return;
		}

		var scroll = document.querySelector('.fas-related-scroll');
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					load(entry.target);
					io.unobserve(entry.target);
				}
			});
		}, {
			root: scroll || null,
			rootMargin: '120px 80px',
			threshold: 0.01
		});

		imgs.forEach(function (img) {
			io.observe(img);
		});
	}

	function init() {
		moveRelated();

		var scroll = document.querySelector('.fas-related-scroll');
		if (!scroll) {
			return;
		}
		enhanceScroll(scroll);

		// 封面图懒加载（缩略图/大图按需加载）。
		initLazy();

		// 4) 左右箭头：点按平滑切换一屏。
		var prev = document.querySelector('.fas-related-prev');
		var next = document.querySelector('.fas-related-next');
		var step = function (dir) {
			var amount = Math.round(scroll.clientWidth * 0.85);
			if (amount <= 0) {
				amount = 300;
			}
			scroll.scrollBy({ left: dir * amount, behavior: 'smooth' });
		};
		if (prev) {
			prev.addEventListener('click', function () { step(-1); });
		}
		if (next) {
			next.addEventListener('click', function () { step(1); });
		}

		updateNav(scroll);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
