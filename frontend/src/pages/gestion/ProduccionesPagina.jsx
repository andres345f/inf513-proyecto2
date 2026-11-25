import { useCallback } from 'react'

import { useSearchParams } from 'react-router-dom'

import { consultasApi } from '../../api/consultasApi'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TablaDatos from '../../components/plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { cantidadConUnidad, tonoEstado } from '../../utils/etiquetas'

import { formatearFecha, formatearMoneda } from '../../utils/formato'

import './CierreGestion.css'

const ESTADOS = { PLANIFICADA: 'Planificada', EN_CURSO: 'En curso', TERMINADA: 'Terminada', CANCELADA: 'Cancelada' }
const ESTADOS_LOTE = { DISPONIBLE: 'Disponible', AGOTADO: 'Agotado', RETIRADO: 'Retirado' }

const COLUMNAS_LOTES = [
  { clave: 'codigo', etiqueta: 'Lote' },
  { clave: 'elaborado', etiqueta: 'Elaboración', alRender: (l) => formatearFecha(l.elaborado) },
  { clave: 'vence', etiqueta: 'Vencimiento', alRender: (l) => formatearFecha(l.vence) },
  { clave: 'producido', etiqueta: 'Producido', alRender: (l, p) => cantidadConUnidad(l.producido, p.unidad_medida) },
  { clave: 'ofrecido', etiqueta: 'Ofrecido', alRender: (l, p) => cantidadConUnidad(l.ofrecido, p.unidad_medida) },
  { clave: 'precio', etiqueta: 'Precio ofrecido', alRender: (l) => (l.precio != null ? formatearMoneda(l.precio) : '—') },
  { clave: 'estado', etiqueta: 'Estado', alRender: (l) => ESTADOS_LOTE[l.estado] || l.estado },
]

export default function ProduccionesPagina() {
  const [parametros, setParametros] = useSearchParams()
  const lista = useCargaApi(() => consultasApi.produccionesGestion(Object.fromEntries(parametros)), [parametros.toString()])
  const filtrar = useCallback((cambios) => setParametros((actuales) => { const nuevos = new URLSearchParams(actuales); Object.entries(cambios).forEach(([c, v]) => v ? nuevos.set(c, v) : nuevos.delete(c)); if (!('pagina' in cambios)) nuevos.delete('pagina'); return nuevos }), [setParametros])
  const valor = (c) => parametros.get(c) ?? ''

  const filtros = (
    <>
      <label>Buscar<input defaultValue={valor('q')} placeholder="Código, producto o productor" maxLength={60} onBlur={(e) => e.target.value !== valor('q') && filtrar({ q: e.target.value })} onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} /></label>
      <label>Estado<select value={valor('estado')} onChange={(e) => filtrar({ estado: e.target.value })}><option value="">Todos</option>{Object.entries(ESTADOS).map(([c, t]) => <option key={c} value={c}>{t}</option>)}</select></label>
    </>
  )

  return (
    <PaginaListado
      titulo="Producciones y lotes"
      descripcion="Consulta la procedencia y la oferta registrada por los productores afiliados."
      etiquetaFiltros="Filtrar producciones"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      cargando={lista.cargando && !lista.datos}
      error={lista.error}
      resumen={lista.datos ? `${lista.datos.paginacion.total ?? 0} producciones` : null}
      vacio={lista.datos?.producciones.length === 0 ? { titulo: 'No hay producciones con esos filtros', mensaje: 'Prueba quitando el filtro de estado o de búsqueda.' } : null}
      paginacion={lista.datos?.producciones.length ? {
        pagina: lista.datos.paginacion.pagina,
        paginas: lista.datos.paginacion.paginas,
        alCambiar: (pagina) => filtrar({ pagina: String(pagina) }),
      } : null}
    >
      <div className="cierre-gestion__lista">
        {lista.datos?.producciones.map((p) => (
          <details key={p.id} className="cierre-gestion__tarjeta">
            <summary>
              <span><strong>{p.codigo} · {p.producto}</strong><small>{p.productor} ({p.codigo_productor}) · {p.comunidad}</small></span>
              <span><Insignia tono={tonoEstado(p.estado)}>{ESTADOS[p.estado] || p.estado}</Insignia><small>{p.lotes.length} lotes</small></span>
            </summary>
            <div className="cierre-gestion__contenido">
              <dl>
                <dt>Inicio</dt><dd>{formatearFecha(p.fecha_inicio)}</dd>
                <dt>Disponibilidad estimada</dt><dd>{formatearFecha(p.disponible_desde)}</dd>
                <dt>Cantidad estimada</dt><dd>{p.estimado != null ? cantidadConUnidad(p.estimado, p.unidad_medida) : '—'}</dd>
                <dt>Producido</dt><dd>{cantidadConUnidad(p.producido, p.unidad_medida)}</dd>
                <dt>Ubicación</dt><dd>{p.ubicacion || '—'}</dd>
              </dl>
              {p.observacion && <p>{p.observacion}</p>}
              <TablaDatos
                columnas={COLUMNAS_LOTES.map((c) => ({
                  ...c,
                  alRender: c.alRender ? (l) => c.alRender(l, p) : undefined,
                }))}
                filas={p.lotes}
              />
              {p.lotes.length === 0 && <p className="pagina-listado__resumen">Sin lotes registrados.</p>}
            </div>
          </details>
        ))}
      </div>
    </PaginaListado>
  )
}
