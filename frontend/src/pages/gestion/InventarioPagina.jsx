import { useCallback } from 'react'

import { useSearchParams } from 'react-router-dom'

import { consultasApi } from '../../api/consultasApi'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TablaDatos from '../../components/plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { cantidadConUnidad, tonoEstado } from '../../utils/etiquetas'

import { formatearFecha } from '../../utils/formato'

const ESTADOS_LOTE = { DISPONIBLE: 'Disponible', AGOTADO: 'Agotado', VENCIDO: 'Vencido', BLOQUEADO: 'Bloqueado' }

const COLUMNAS = [
  { clave: 'codigo', etiqueta: 'Lote' },
  { clave: 'producto', etiqueta: 'Producto' },
  { clave: 'disponible', etiqueta: 'Disponible', alRender: (l) => cantidadConUnidad(l.disponible, l.unidad_medida) },
  { clave: 'reservado', etiqueta: 'Reservado', alRender: (l) => cantidadConUnidad(l.reservado, l.unidad_medida) },
  { clave: 'libre', etiqueta: 'Libre', alRender: (l) => <strong>{cantidadConUnidad(l.libre, l.unidad_medida)}</strong> },
  { clave: 'estado', etiqueta: 'Estado', alRender: (l) => <Insignia tono={tonoEstado(l.estado)}>{ESTADOS_LOTE[l.estado]}</Insignia> },
  { clave: 'vence', etiqueta: 'Vence', alRender: (l) => (l.vence ? formatearFecha(l.vence) : '—') },
  { clave: 'origen', etiqueta: 'Origen', alRender: (l) => `${l.productor} · ${l.comunidad}` },
  { clave: 'ubicacion', etiqueta: 'Ubicación', alRender: (l) => l.ubicacion ?? '—' },
]

/** Inventario por lote: saldo físico, reservado y libre, con origen y vencimiento. */
export default function InventarioPagina() {
  const [parametros, setParametros] = useSearchParams()
  const clave = parametros.toString()
  const lista = useCargaApi(() => consultasApi.inventario(Object.fromEntries(parametros)), [clave])

  const filtrar = useCallback((cambios) => {
    setParametros((actuales) => {
      const nuevos = new URLSearchParams(actuales)
      Object.entries(cambios).forEach(([c, v]) => (v ? nuevos.set(c, v) : nuevos.delete(c)))
      if (!('pagina' in cambios)) nuevos.delete('pagina')
      return nuevos
    })
  }, [setParametros])

  const valor = (c) => parametros.get(c) ?? ''

  const filtros = (
    <>
      <label>Buscar<input defaultValue={valor('q')} maxLength={60} placeholder="Producto o lote"
        onBlur={(e) => e.target.value !== valor('q') && filtrar({ q: e.target.value })}
        onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} /></label>
      <label>Estado del lote<select value={valor('estado')} onChange={(e) => filtrar({ estado: e.target.value })}>
        <option value="">Todos</option>
        {Object.entries(ESTADOS_LOTE).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Vencimiento<select value={valor('por_vencer')} onChange={(e) => filtrar({ por_vencer: e.target.value })}>
        <option value="">Todos</option><option value="1">Vence en 30 días</option>
      </select></label>
    </>
  )

  return (
    <PaginaListado
      titulo="Inventario"
      descripcion="Saldo físico, reservado y libre de cada lote, con su origen y vencimiento."
      etiquetaFiltros="Filtrar inventario"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      cargando={lista.cargando && !lista.datos}
      error={lista.error}
      resumen={lista.datos ? `${lista.datos.paginacion.total ?? 0} lotes` : null}
      vacio={lista.datos?.lotes.length === 0 ? { titulo: 'No hay lotes con esos filtros', mensaje: 'Prueba quitando el filtro de vencimiento o de estado.' } : null}
      paginacion={lista.datos?.lotes.length ? {
        pagina: lista.datos.paginacion.pagina,
        paginas: lista.datos.paginacion.paginas,
        alCambiar: (pagina) => filtrar({ pagina: String(pagina) }),
      } : null}
    >
      <TablaDatos columnas={COLUMNAS} filas={lista.datos?.lotes ?? []} />
    </PaginaListado>
  )
}
