import './Tarjeta.css'

/**
 * Contenedor estándar con superficie, borde y sombra del tema.
 *
 * - titulo / subtitulo: cabecera opcional; `acciones` queda a la derecha.
 * - variante: 'estandar' (sombra media) | 'elevada' (sombra fuerte, p. ej.
 *   modales y héroes) | 'plana' (sin sombra, para agrupar dentro de otra).
 * - interactiva: eleva la tarjeta al pasar el cursor (para tarjetas enlace).
 * - franja: 'info' | 'exito' | 'aviso' | 'error' dibuja una barra lateral de
 *   color semántico.
 */
export default function Tarjeta({
  titulo,
  subtitulo,
  acciones = null,
  variante = 'estandar',
  interactiva = false,
  franja = null,
  children,
  className = '',
  como: Etiqueta = 'section',
}) {
  const clases = [
    'tarjeta',
    variante !== 'estandar' && `tarjeta--${variante}`,
    interactiva && 'tarjeta--interactiva',
    franja && `tarjeta--franja-${franja}`,
    className,
  ].filter(Boolean).join(' ')

  return (
    <Etiqueta className={clases}>
      {(titulo || acciones) && (
        <header className="tarjeta__cabecera">
          <div>
            {titulo && <h2 className="tarjeta__titulo">{titulo}</h2>}
            {subtitulo && <p className="tarjeta__subtitulo">{subtitulo}</p>}
          </div>
          {acciones && <div className="tarjeta__acciones">{acciones}</div>}
        </header>
      )}
      {children}
    </Etiqueta>
  )
}
