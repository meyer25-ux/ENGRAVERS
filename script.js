(function () {
  'use strict';

  /* ─── Phone Carousel (preserved logic) ─── */
  const cases = document.querySelectorAll('.case-wrap');
  let current = 1;
  const total = cases.length;

  function getClass(i) {
    const diff = (i - current + total) % total;
    if (diff === 0) return 'active';
    if (diff === 1) return 'right';
    if (diff === total - 1) return 'left';
    return 'hidden';
  }

  function update() {
    cases.forEach(function (c, i) {
      c.className = 'case-wrap ' + getClass(i);
    });
  }

  setInterval(function () {
    current = (current + 1) % total;
    update();
  }, 2500);

  /* ─── Floating Particles ─── */
  var particlesContainer = document.getElementById('particles');
  var prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  if (particlesContainer && !prefersReduced) {
    var colors = ['#6ca824', '#2d5a1b', '#4a7c35', '#95d650'];
    var count = window.innerWidth < 640 ? 12 : 24;

    for (var p = 0; p < count; p++) {
      var dot = document.createElement('span');
      dot.className = 'particle';
      var size = Math.random() * 5 + 2;
      dot.style.cssText =
        'width:' + size + 'px;height:' + size + 'px;' +
        'left:' + (Math.random() * 100) + '%;' +
        'bottom:' + (-Math.random() * 20) + '%;' +
        'background:' + colors[Math.floor(Math.random() * colors.length)] + ';' +
        'animation-duration:' + (Math.random() * 12 + 8) + 's;' +
        'animation-delay:' + (Math.random() * 8) + 's;';
      particlesContainer.appendChild(dot);
    }
  }

  /* ─── Scroll Reveal ─── */
  var revealEls = document.querySelectorAll('.reveal');

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    revealEls.forEach(function (el) { observer.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add('visible'); });
  }

  /* ─── Mouse Parallax ─── */
  var hero = document.getElementById('hero');
  var heroBg = hero ? hero.querySelector('.hero-bg') : null;
  var glows = hero ? hero.querySelectorAll('.gradient-glow') : [];
  var floatingCases = hero ? hero.querySelectorAll('.floating-case') : [];
  var parallaxEnabled = !prefersReduced && window.matchMedia('(pointer: fine)').matches;

  if (hero && heroBg && parallaxEnabled) {
    var ticking = false;

    hero.addEventListener('mousemove', function (e) {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () {
        var rect = hero.getBoundingClientRect();
        var x = (e.clientX - rect.left) / rect.width - 0.5;
        var y = (e.clientY - rect.top) / rect.height - 0.5;

        glows.forEach(function (glow, i) {
          var factor = (i + 1) * 12;
          glow.style.transform = 'translate(' + (x * factor) + 'px, ' + (y * factor) + 'px)';
        });

        floatingCases.forEach(function (fc, i) {
          var f = (i + 1) * 8;
          fc.style.transform = 'translate(' + (x * f) + 'px, ' + (y * f) + 'px)';
        });

        ticking = false;
      });
    });
  }
})();