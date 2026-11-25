import { useCallback, useMemo } from 'react'

import { Link, useSearchParams } from 'react-router-dom'

import { PackageSearch } from 'lucide-react'

import { comercioApi } from '../../api/comercioApi'

import Boton from '../../components/ui/Boton'

import Insignia from '../../components/ui/Insignia'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TarjetaItem from '../../components/plantillas/TarjetaItem'

import { useCargaApi } from '../../hooks/useCargaApi'

import { ESTADOS_PEDIDO, MODALIDADES, TIPOS_PEDIDO, tonoEstado } from '../../utils/etiquetas'

import { formatearFechaHora, formatearMoneda } from '../../utils/formato'

const CLAVES = ['estado', 'tipo', 'desde', 'hasta', 'pagina']

export default function MisPedidosPagina() {
  const [parametros, setParametros] = useSearchParams()
  const valores = useMemo(() => Object.fromEntries(CLAVES.map((c) => [c, parametros.get(c) || ''])), [parametros])
  const pedidos = useCargaApi(() => comercioApi.misPedidos(Object.fromEntries(parametros)), [parametros.toString()])
  const cambiar = useCallback((cambios) => setParametros((actuales) => { const nuevos = new URLSearchParams(actuales); Object.entries(cambios).forEach(([c, v]) => v ? nuevos.set(c, v) : nuevos.delete(c)); if (!('pagina' in cambios)) nuevos.delete('pagina'); return nuevos }), [setParametros])

  const filtros = (
    <>
      <label>Estado<select value={valores.estado} onChange={(e) => cambiar({ estado: e.target.value })}><option value="">Todos</option>{Object.entries(ESTADOS_PEDIDO).map(([v, t]) => <option key={v} value={v}>{t}</option>)}</select></label>
      <label>Tipo<select value={valores.tipo} onChange={(e) => cambiar({ tipo: e.target.value })}><option value="">Todos</option>{Object.entries(TIPOS_PEDIDO).map(([v, t]) => <option key={v} value={v}>{t}</option>)}</select></label>
      <label>Desde<input type="date" value={valores.desde} onChange={(e) => cambiar({ desde: e.target.value })} /></label>
      <label>Hasta<input type="date" min={valores.desde || undefined} value={valores.hasta} onChange={(e) => cambiar({ hasta: e.target.value })} /></label>
    </>
  )

  return (
    <PaginaListado
      titulo="Mis pedidos"
      descripcion="Consulta el estado, la entrega y los pagos de tus compras."
      etiquetaFiltros="Filtrar pedidos"
      alLimpiarFiltros={() => setParametros({})}
      filtros={filtros}
      filtrosActivos={CLAVES.filter((c) => c !== 'pagina' && valores[c]).length}
      cargando={pedidos.cargando && !pedidos.datos}
      error={pedidos.error}
      actualizando={pedidos.actualizando}
      alReintentar={pedidos.recargar}
      resumen={pedidos.datos ? `${pedidos.datos.paginacion.total || 0} ${pedidos.datos.paginacion.total === 1 ? 'pedido encontrado' : 'pedidos encontrados'}` : null}
      vacio={pedidos.datos?.pedidos.length === 0 ? {
        titulo: 'No tienes pedidos con esos filtros',
        mensaje: 'Prueba quitando algún filtro o mira el catálogo para hacer tu primera compra.',
        icono: PackageSearch,
        accion: <Boton como={Link} to="/catalogo">Ir al catálogo</Boton>,
      } : null}
      paginacion={pedidos.datos?.pedidos.length ? {
        pagina: pedidos.datos.paginacion.pagina,
        paginas: pedidos.datos.paginacion.paginas,
        alCambiar: (pagina) => cambiar({ pagina: String(pagina) }),
      } : null}
    >
      <div className="pagina-listado__lista">
        {pedidos.datos?.pedidos.map((p) => (
          <TarjetaItem
            key={p.id}
            to={`/mis-pedidos/${p.id}`} state={{ consultaPedidos: parametros.toString() }}
            titulo={p.codigo}
            subtitulo={formatearFechaHora(p.fecha)}
            monto={formatearMoneda(p.total)}
            datos={[
              { etiqueta: 'Estado', valor: <Insignia tono={tonoEstado(p.estado)}>{ESTADOS_PEDIDO[p.estado] || p.estado}</Insignia> },
              { etiqueta: 'Tipo', valor: TIPOS_PEDIDO[p.tipo] || p.tipo },
              { etiqueta: 'Entrega', valor: MODALIDADES[p.modalidad] || p.modalidad },
            ]}
          />
        ))}
      </div>
    </PaginaListado>
  )
}
