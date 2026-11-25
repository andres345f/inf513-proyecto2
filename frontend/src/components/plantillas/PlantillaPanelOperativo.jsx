import { RefreshCw } from 'lucide-react'
import Alerta from '../ui/Alerta'
import Cargando from '../ui/Cargando'
import EstadoVacio from './EstadoVacio'
import './PlantillaPanelOperativo.css'

/**
 * P4 · Plantilla de panel operativo (bandeja de trabajo, paneles por rol).
 *
 * - colas: [{ clave, etiqueta, cantidad, tono = 'info', icono, descripcion }]
 *   Botones de prioridad; los números enlazan con la lista de esa cola.
 * - colasActiva / alSeleccionar: cola visible y su cambio.
 * - vacio: { titulo, mensaje, accion } cuando la cola activa está sin trabajo.
 * - children: contenido de la cola activa (lista con acciones siguientes).
 * - recargar: función opcional para refrescar los datos.
 */
export default function PlantillaPanelOperativo({
  titulo,
  descripcion,
  fecha,
  colas = [],
  colasActiva,
  alSeleccionar,
  vacio = null,
  cargando = false,
  error = null,
  recargar = null,
  className = '',
  children,
}) {
  return (
    <section className={`plantilla-panel ${className}`.trim()} aria-busy={cargando}>
      <header className="plantilla-panel__encabezado">
        <div>
          <h1 className="plantilla-panel__titulo">{titulo}</h1>
          <p className="plantilla-panel__subtitulo">
            {fecha && <>{fecha} · </>}
            {descripcion}
          </p>
        </div>
        {recargar && (
          <button type="button" className="plantilla-panel__recargar" onClick={recargar}>
            <RefreshCw size={16} aria-hidden="true" /> Actualizar
          </button>
        )}
      </header>

      {error && <Alerta tipo="error">{error}</Alerta>}

      <div className="plantilla-panel__cuerpo">
        <nav className="plantilla-panel__colas" aria-label="Colas de trabajo">
          <ul className="plantilla-panel__colas-lista">
            {colas.map((cola) => {
              const clases = ['plantilla-panel__cola', `plantilla-panel__cola--${cola.tono || 'info'}`,
                cola.clave === colasActiva && 'plantilla-panel__cola--activa'].filter(Boolean).join(' ')
              return (
                <li key={cola.clave}>
                  <button type="button" className={clases} aria-pressed={cola.clave === colasActiva}
                    onClick={() => alSeleccionar?.(cola.clave)}>
                    <span className="plantilla-panel__cola-textos">
                      <span className="plantilla-panel__cola-etiqueta">{cola.etiqueta}</span>
                      {cola.descripcion && <span className="plantilla-panel__cola-descripcion">{cola.descripcion}</span>}
                    </span>
                    <strong className="plantilla-panel__cola-cantidad">{cola.cantidad}</strong>
                  </button>
                </li>
              )
            })}
          </ul>
        </nav>

        <div className="plantilla-panel__contenido" aria-live="polite">
          {cargando && <Cargando />}
          {!cargando && !error && (vacio ? <EstadoVacio {...vacio} /> : children)}
        </div>
      </div>
    </section>
  )
}
