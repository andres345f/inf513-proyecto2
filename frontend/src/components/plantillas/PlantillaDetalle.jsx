import { Link } from 'react-router-dom'
import Migas from '../layout/Migas'
import './PlantillaDetalle.css'

/**
 * P2 · Plantilla de página de detalle (ruta propia).
 *
 * - migas: [{ etiqueta, ruta? }] — la primera suele volver al listado.
 * - titulo / descripcion: identidad principal del recurso (código, tipo…).
 * - estado: nodo (normalmente una Insignia) con el estado actual.
 * - acciones: nodo con las acciones posibles según estado y rol.
 * - resumen: nodo de ventana lateral (montos, cliente, fechas…).
 * - children: contenido principal (línea de tiempo, bloques relacionados).
 */
export default function PlantillaDetalle({
  migas = [],
  titulo,
  descripcion,
  estado = null,
  acciones = null,
  resumen = null,
  className = '',
  children,
}) {
  const conVolver = migas.length > 0 && migas[0].ruta
  const regreso = conVolver && { to: migas[0].ruta, etiqueta: `Volver a ${migas[0].etiqueta}` }

  return (
    <section className={`plantilla-detalle ${className}`.trim()}>
      <div className="plantilla-detalle__encabezado">
        {migas.length > 0 && <Migas items={migas} />}
        {regreso && (
          <Link to={regreso.to} className="boton boton--fantasma plantilla-detalle__volver no-imprimir">
            {regreso.etiqueta}
          </Link>
        )}
        <div className="plantilla-detalle__identidad">
          <h1 className="plantilla-detalle__titulo">{titulo}</h1>
          {estado && <span className="plantilla-detalle__estado">{estado}</span>}
          {acciones && <div className="plantilla-detalle__acciones no-imprimir">{acciones}</div>}
        </div>
        {descripcion && <p className="plantilla-detalle__descripcion">{descripcion}</p>}
      </div>

      <div className={`plantilla-detalle__cuerpo${resumen ? ' plantilla-detalle--con-lateral' : ''}`.trim()}>
        {resumen && (
          <aside className="plantilla-detalle__lateral" aria-label="Resumen">
            {resumen}
          </aside>
        )}
        <div className="plantilla-detalle__principal">{children}</div>
      </div>
    </section>
  )
}
