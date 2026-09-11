/* =====================================================
   TORNALYX – galeria.js
   Carrusel de la sección "Pensado para todo tipo de
   competencia": las fotos pasan solas una por una y se
   pueden elegir con los puntos de abajo.
   ===================================================== */

'use strict';

(function () {

/** Milisegundos que se muestra cada foto. */
const INTERVALO = 4000;

function initGaleria() {
  const galeria = document.querySelector('.galeria');
  if (!galeria) return;

  const slides = [...galeria.querySelectorAll('.galeria__slide')];
  const puntos = galeria.querySelector('.galeria__puntos');
  if (slides.length < 2) return;

  let actual = 0;
  let timer  = null;

  /* Un punto por foto; se generan acá para no repetir el marcado en el HTML. */
  slides.forEach((_, i) => {
    const punto = document.createElement('button');
    punto.type = 'button';
    punto.className = 'galeria__punto' + (i === 0 ? ' is-active' : '');
    punto.setAttribute('role', 'tab');
    punto.setAttribute('aria-label', `Ver foto ${i + 1} de ${slides.length}`);
    punto.setAttribute('aria-selected', String(i === 0));
    punto.addEventListener('click', () => {
      mostrar(i);
      reiniciar();          // al elegir a mano, se reinicia la cuenta
    });
    puntos.appendChild(punto);
  });

  const bolitas = [...puntos.children];

  function mostrar(i) {
    slides[actual].classList.remove('is-active');
    bolitas[actual].classList.remove('is-active');
    bolitas[actual].setAttribute('aria-selected', 'false');

    actual = (i + slides.length) % slides.length;

    slides[actual].classList.add('is-active');
    bolitas[actual].classList.add('is-active');
    bolitas[actual].setAttribute('aria-selected', 'true');
  }

  function arrancar() {
    timer = setInterval(() => mostrar(actual + 1), INTERVALO);
  }
  function reiniciar() {
    clearInterval(timer);
    arrancar();
  }

  /* Al pasar el mouse se pausa, para poder mirar una foto con calma. */
  galeria.addEventListener('mouseenter', () => clearInterval(timer));
  galeria.addEventListener('mouseleave', arrancar);

  /* Sin la pestaña a la vista no tiene sentido seguir rotando. */
  document.addEventListener('visibilitychange', () => {
    document.hidden ? clearInterval(timer) : reiniciar();
  });

  arrancar();
}

document.addEventListener('DOMContentLoaded', initGaleria);

})();
