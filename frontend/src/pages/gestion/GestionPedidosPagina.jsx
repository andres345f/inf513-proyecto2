import { useCallback } from 'react'

import { Link, useSearchParams } from 'react-router-dom'

import { gestionApi } from '../../api/gestionApi'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TablaDatos from '../../components/plantillas/TablaDatos'

import { useCargaApi } from '../../hooks/useCargaApi'

import { ESTADOS_PEDIDO, MODALIDADES, TIPOS_PEDIDO, tonoEstado } from '../../utils/etiquetas'

import { formatearFechaHora, formatearMoneda } from '../../utils/formato'

/** Bandeja de pedidos del Personal de FEDER con filtros del lado del servidor. */

const COLUMNAS = [
  { clave: 'codigo', etiqueta: 'Código', alRender: (p) => <Link to={`/gestion/pedidos/${p.id}`}>{p.codigo}</Link> },
  { clave: 'cliente', etiqueta: 'Cliente' },
  { clave: 'fecha', etiqueta: 'Fecha', alRender: (p) => formatearFechaHora(p.fecha) },
  { clave: 'tipo', etiqueta: 'Tipo', alRender: (p) => TIPOS_PEDIDO[p.tipo] },
  { clave: 'modalidad', etiqueta: 'Entrega', alRender: (p) => MODALIDADES[p.modalidad] ?? '—' },
  { clave: 'estado', etiqueta: 'Estado', alRender: (p) => <Insignia tono={tonoEstado(p.estado)}>{ESTADOS_PEDIDO[p.estado]}</Insignia> },
  { clave: 'total', etiqueta: 'Total', alRender: (p) => formatearMoneda(p.total) },
  { clave: 'pagado', etiqueta: 'Pagado', alRender: (p) => formatearMoneda(p.pagado) },
]

export default function GestionPedidosPagina() {
  const [parametros, setParametros] = useSearchParams()
  const clave = parametros.toString()
  const listado = useCargaApi(() => gestionApi.pedidos(Object.fromEntries(parametros)), [clave])

  const filtrar = useCallback((cambios) => {
    setParametros((actuales) => {
      const nuevos = new URLSearchParams(actuales)
      Object.entries(cambios).forEach(([c, v]) => (v ? nuevos.set(c, v) : nuevos.delete(c)))
      if (!('pagina' in cambios)) nuevos.delete('pagina')
      return nuevos
    })
  }, [setParametros])

  const cambiar = (e) => filtrar({ [e.target.name]: e.target.value })
  const valor = (c) => parametros.get(c) || ''

  const filtros = (
    <>
      <label>Buscar<input type="search" name="q" defaultValue={valor('q')} maxLength={60} placeholder="Código o cliente"
        onKeyDown={(e) => e.key === 'Enter' && filtrar({ q: e.currentTarget.value })} onBlur={(e) => filtrar({ q: e.target.value })} /></label>
      <label>Estado<select name="estado" value={valor('estado')} onChange={cambiar}>
        <option value="">Todos</option>
        {Object.entries(ESTADOS_PEDIDO).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Tipo<select name="tipo" value={valor('tipo')} onChange={cambiar}>
        <option value="">Todos</option>
        {Object.entries(TIPOS_PEDIDO).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Entrega<select name="modalidad" value={valor('modalidad')} onChange={cambiar}>
        <option value="">Todas</option>
        {Object.entries(MODALIDADES).map(([c, t]) => <option key={c} value={c}>{t}</option>)}
      </select></label>
      <label>Desde<input type="date" name="desde" value={valor('desde')} onChange={cambiar} /></label>
      <label>Hasta<input type="date" name="hasta" value={valor('hasta')} onChange={cambiar} /></label>
    </>
  )

  return (
    <PaginaListado
      titulo="Pedidos"
      descripcion="Bandeja de trabajo del personal de FEDER: filtra, revisa y avanza cada pedido."
      etiquetaFiltros="Filtrar pedidos"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      cargando={listado.cargando && !listado.datos}
      error={listado.error}
      resumen={listado.datos ? `${listado.datos.paginacion.total ?? 0} ${listado.datos.paginacion.total === 1 ? 'pedido' : 'pedidos'}` : null}
      vacio={listado.datos?.pedidos.length === 0 ? { titulo: 'No hay pedidos con esos filtros', mensaje: 'Prueba ampliando el rango de fechas o quitando algún filtro.' } : null}
      paginacion={listado.datos?.pedidos.length ? {
        pagina: listado.datos.paginacion.pagina,
        paginas: listado.datos.paginacion.paginas,
        alCambiar: (pagina) => filtrar({ pagina: String(pagina) }),
      } : null}
    >
      <TablaDatos columnas={COLUMNAS} filas={listado.datos?.pedidos ?? []} />
    </PaginaListado>
  )
}
