import { Link } from 'react-router-dom'

import './TarjetaItem.css'

/**
 * Ítem estándar de un listado (pedidos, facturas, devoluciones, pagos…).
 * Reemplaza las tarjetas hechas a mano en cada página.
 *
 * - to: si se indica, todo el ítem es un enlace y gana realce al pasar el cursor.
 * - titulo / subtitulo: identidad del ítem (código y fecha, p. ej.).
 * - monto: importe alineado a la derecha en la cabecera.
 * - insignia: nodo (Insignia) junto al monto.
 * - datos: [{ etiqueta, valor }] → rejilla clave-valor bajo la cabecera.
 * - icono: componente lucide opcional a la izquierda.
 * - destacada: franja izquierda de color primario (p. ej. sin leer).
 * - acciones: nodo con botones/enlaces al pie (no dentro del enlace `to`).
 * - children: contenido libre adicional (motivo, mensaje, cuotas…).
 */
export default function TarjetaItem({
  to = null,
  titulo,
  subtitulo = null,
  monto = null,
  insignia = null,
  datos = [],
  icono: Icono = null,
  destacada = false,
  acciones = null,
  className = '',
  children,
  ...resto
}) {
  const clases = [
    'tarjeta-item',
    to && 'tarjeta-item--enlace',
    destacada && 'tarjeta-item--destacada',
    className,
  ].filter(Boolean).join(' ')

  const cuerpo = (
    <>
      <div className="tarjeta-item__cabecera">
        {Icono && (
          <span className="tarjeta-item__icono" aria-hidden="true">
            <Icono size={22} />
          </span>
        )}
        <div className="tarjeta-item__identidad">
          <strong className="tarjeta-item__titulo">{titulo}</strong>
          {subtitulo && <span className="tarjeta-item__subtitulo">{subtitulo}</span>}
        </div>
        <div className="tarjeta-item__lateral">
          {monto != null && <strong className="tarjeta-item__monto">{monto}</strong>}
          {insignia}
        </div>
      </div>

      {datos.length > 0 && (
        <dl className="tarjeta-item__datos">
          {datos.map((dato) => (
            <div key={dato.etiqueta}>
              <dt>{dato.etiqueta}</dt>
              <dd>{dato.valor}</dd>
            </div>
          ))}
        </dl>
      )}

      {children && <div className="tarjeta-item__extra">{children}</div>}

      {acciones && <div className="tarjeta-item__acciones">{acciones}</div>}
    </>
  )

  if (to) {
    return (
      <Link to={to} className={clases} {...resto}>
        {cuerpo}
      </Link>
    )
  }

  return <article className={clases} {...resto}>{cuerpo}</article>
}
