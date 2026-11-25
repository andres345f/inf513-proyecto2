import { useState } from 'react'
import { Link } from 'react-router-dom'
import { devolucionesApi } from '../../api/devolucionesApi'
import { gestionApi } from '../../api/gestionApi'
import { productoresApi } from '../../api/productoresApi'
import { consultasApi } from '../../api/consultasApi'
import Insignia from '../../components/ui/Insignia'
import EstadoVacio from '../../components/plantillas/EstadoVacio'
import PlantillaPanelOperativo from '../../components/plantillas/PlantillaPanelOperativo'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useSesion } from '../../hooks/useSesion'
import { ESTADOS_PEDIDO, ESTADOS_ENTREGA, tonoEstado } from '../../utils/etiquetas'
import { aInstante, fechaCivilHoy, formatearFechaHora, formatearMoneda, sumarDiasCivil } from '../../utils/formato'
import './GestionPedidos.css'
import './PanelOperaciones.css'

/** Registro unificado de una cola: de dónde viene, qué es y a dónde se trabaja. */
const registro = (fuente, id, titulo, persona, fecha, estado, etiquetaEstado, monto, ruta) =>
  ({ fuente, id, titulo, persona, fecha, estado, etiquetaEstado, monto, ruta })

/** Ventana de la cola «Recientes»: 7 fechas calendario incluyendo hoy (hoy − 6 días,
    ya que el backend filtra con whereDate >= desde sobre fecha_creacion/fecha_compra),
    AAAA-MM-DD del calendario de Bolivia (utils/formato), sin desfase UTC. Se calcula una sola vez al cargar el módulo. */
const DESDE_RECIENTES = sumarDiasCivil(fechaCivilHoy(), -6)

/**
 * Bandeja del Personal de FEDER organizada por categoría operativa:
 * Requiere atención · Hoy · En proceso · Problemas · Recientes.
 *
 * Los endpoints de listado aceptan un solo estado por petición, así que cada
 * estado se consulta por separado (solo GET, ?pagina=1; «Recientes» además con
 * desde = hoy − 6 días sobre fecha_creacion del pedido / fecha_compra) y la cola
 * agrega los conteos (paginacion.total, sin depender del tamaño de página) y una
 * muestra de filas. Si en una fase posterior se requiere filtrar por la fecha real
 * de entrega/recepción, será necesario un endpoint nuevo; no se crea N2 ahora.
 */
export default function PanelOperacionesPagina() {
  const { tienePermiso } = useSesion()
  const [colaActiva, setColaActiva] = useState(null)
  const puede = {
    pagos: tienePermiso('pagos.gestionar'),
    pedidos: tienePermiso('pedidos.gestionar'),
    entregas: tienePermiso('entregas.gestionar'),
    devoluciones: tienePermiso('devoluciones.gestionar'),
    compras: tienePermiso('compras.gestionar'),
    productores: tienePermiso('productores.gestionar'),
  }

  // Una consulta por (dominio, estado); null si el rol perdió el permiso del dominio.
  const pagosPend = useCargaApi(() => (puede.pagos ? gestionApi.pagos({ estado: 'PENDIENTE', pagina: 1 }) : Promise.resolve(null)), [puede.pagos])
  const devsSolicitadas = useCargaApi(() => (puede.devoluciones ? devolucionesApi.listar({ estado: 'SOLICITADA', pagina: 1 }) : Promise.resolve(null)), [puede.devoluciones])
  const devsAprobadas = useCargaApi(() => (puede.devoluciones ? devolucionesApi.listar({ estado: 'APROBADA', pagina: 1 }) : Promise.resolve(null)), [puede.devoluciones])
  const productoresPend = useCargaApi(() => (puede.productores ? productoresApi.productores({ estado: 'PENDIENTE', pagina: 1 }) : Promise.resolve(null)), [puede.productores])
  const entregasPend = useCargaApi(() => (puede.entregas ? gestionApi.entregas({ estado: 'PENDIENTE', pagina: 1 }) : Promise.resolve(null)), [puede.entregas])
  const entregasLista = useCargaApi(() => (puede.entregas ? gestionApi.entregas({ estado: 'LISTA_PARA_RECOJO', pagina: 1 }) : Promise.resolve(null)), [puede.entregas])
  const entregasCamino = useCargaApi(() => (puede.entregas ? gestionApi.entregas({ estado: 'EN_CAMINO', pagina: 1 }) : Promise.resolve(null)), [puede.entregas])
  const entregasFallidas = useCargaApi(() => (puede.entregas ? gestionApi.entregas({ estado: 'FALLIDA', pagina: 1 }) : Promise.resolve(null)), [puede.entregas])
  const entregasRetornadas = useCargaApi(() => (puede.entregas ? gestionApi.entregas({ estado: 'RETORNADA', pagina: 1 }) : Promise.resolve(null)), [puede.entregas])
  const entregasNoRecuperadas = useCargaApi(() => (puede.entregas ? gestionApi.entregas({ estado: 'NO_RECUPERADA', pagina: 1 }) : Promise.resolve(null)), [puede.entregas])
  const pedidosPrep = useCargaApi(() => (puede.pedidos ? gestionApi.pedidos({ estado: 'EN_PREPARACION', pagina: 1 }) : Promise.resolve(null)), [puede.pedidos])
  const pedidosDesp = useCargaApi(() => (puede.pedidos ? gestionApi.pedidos({ estado: 'DESPACHADO', pagina: 1 }) : Promise.resolve(null)), [puede.pedidos])
  // Recientes: ventana real de los últimos 7 días; la fecha se calcula una sola
  // vez al cargar el módulo (local, sin desfase UTC) con DESDE_RECIENTES.
  const pedidosEntregados = useCargaApi(() => (puede.pedidos ? gestionApi.pedidos({ estado: 'ENTREGADO', desde: DESDE_RECIENTES, pagina: 1 }) : Promise.resolve(null)), [puede.pedidos, DESDE_RECIENTES])
  const comprasRegistradas = useCargaApi(() => (puede.compras ? consultasApi.comprasGestion({ estado: 'REGISTRADA', pagina: 1 }) : Promise.resolve(null)), [puede.compras])
  const comprasParciales = useCargaApi(() => (puede.compras ? consultasApi.comprasGestion({ estado: 'RECIBIDA_PARCIAL', pagina: 1 }) : Promise.resolve(null)), [puede.compras])
  const comprasRecibidas = useCargaApi(() => (puede.compras ? consultasApi.comprasGestion({ estado: 'RECIBIDA', desde: DESDE_RECIENTES, pagina: 1 }) : Promise.resolve(null)), [puede.compras, DESDE_RECIENTES])

  const consultas = [pagosPend, devsSolicitadas, devsAprobadas, productoresPend, entregasPend, entregasLista,
    entregasCamino, entregasFallidas, entregasRetornadas, entregasNoRecuperadas, pedidosPrep, pedidosDesp,
    pedidosEntregados, comprasRegistradas, comprasParciales, comprasRecibidas]
  const permitidas = [puede.pagos, puede.devoluciones, puede.devoluciones, puede.productores, puede.entregas,
    puede.entregas, puede.entregas, puede.entregas, puede.entregas, puede.entregas, puede.pedidos, puede.pedidos,
    puede.pedidos, puede.compras, puede.compras, puede.compras]
  const cargando = consultas.some((c, i) => permitidas[i] && c.cargando)
  const error = consultas.filter((c, i) => permitidas[i]).map((c) => c.error).find(Boolean) ?? null

  const total = (c) => c.datos?.paginacion?.total ?? 0
  const entregasOperativas = total(entregasPend) + total(entregasLista) + total(entregasCamino)
  const entregasProblemas = total(entregasFallidas) + total(entregasRetornadas) + total(entregasNoRecuperadas)
  const comprasPorRecibir = total(comprasRegistradas) + total(comprasParciales)
  const pedidosPrepDesp = total(pedidosPrep) + total(pedidosDesp)

  // Ruta de una fila asociada a un pedido: enlaza a /gestion/pedidos/:id solo si
  // puede.pedidos es true; si no, usa la ruta autorizada del propio dominio.
  const rutaPedidoODominio = (pedidoId, rutaDominio) => (puede.pedidos ? `/gestion/pedidos/${pedidoId}` : rutaDominio)

  // Filas por dominio (muestra de las más recientes de cada consulta).
  const filasPagos = (pagosPend.datos?.pagos ?? []).map((p) => registro('pagos', p.id, `Pago ${METODOS_TEXTO[p.metodo]} · pedido ${p.pedido.codigo}`, p.cliente, p.fecha, p.estado, 'Pendiente de confirmación', p.monto, rutaPedidoODominio(p.pedido.id, '/gestion/pagos')))
  const filasDevsSol = (devsSolicitadas.datos?.devoluciones ?? []).map((d) => registro('devoluciones', d.id, `Devolución ${d.codigo} · ${d.producto}`, d.cliente, d.fecha_solicitud, d.estado, 'Por resolver', d.importe, rutaPedidoODominio(d.pedido.id, '/gestion/devoluciones')))
  const filasDevsApro = (devsAprobadas.datos?.devoluciones ?? []).map((d) => registro('devoluciones', d.id, `Devolución ${d.codigo} · ${d.producto}`, d.cliente, d.fecha_solicitud, d.estado, 'Aprobada por completar', d.importe, rutaPedidoODominio(d.pedido.id, '/gestion/devoluciones')))
  const filasProductores = (productoresPend.datos?.productores ?? []).map((p) => registro('productores', p.id, `Solicitud de afiliación · ${p.nombre}`, p.correo, p.fecha_solicitud, p.estado, 'Por revisar', null, '/gestion/productores'))
  const filasEntregas = (lista) => (lista.datos?.entregas ?? []).map((e) => registro('entregas', e.id, `Entrega ${MODALIDAD_TEXTO[e.modalidad]} · pedido ${e.pedido.codigo}`, e.cliente, e.pedido.fecha, e.estado, ESTADOS_ENTREGA[e.estado], e.costo_envio, rutaPedidoODominio(e.pedido.id, '/gestion/entregas')))
  const filasPedidos = (lista) => (lista.datos?.pedidos ?? []).map((p) => registro('pedidos', p.id, `Pedido ${p.codigo}`, p.cliente, p.fecha, p.estado, ESTADOS_PEDIDO[p.estado], p.total, `/gestion/pedidos/${p.id}`))
  const filasCompras = (lista, etiqueta) => (lista.datos?.compras ?? []).map((c) => registro('compras', c.id, `Compra ${c.codigo}`, c.productor, c.fecha, c.estado, etiqueta, c.total, '/gestion/compras'))

  // Enlaces de cada cola: solo la ruta de un dominio cuyo permiso sigue vigente.
  const RUTAS = {
    pagos: { dominio: 'pagos', ruta: '/gestion/pagos' },
    pedidos: { dominio: 'pedidos', ruta: '/gestion/pedidos' },
    entregas: { dominio: 'entregas', ruta: '/gestion/entregas' },
    devoluciones: { dominio: 'devoluciones', ruta: '/gestion/devoluciones' },
    compras: { dominio: 'compras', ruta: '/gestion/compras' },
    productores: { dominio: 'productores', ruta: '/gestion/productores' },
  }

  const colas = [
    {
      clave: 'atencion', etiqueta: 'Requiere atención', tono: 'aviso',
      descripcion: 'Dinero y solicitudes esperando una decisión',
      cantidad: total(pagosPend) + total(devsSolicitadas) + total(productoresPend),
      filas: [...filasPagos, ...filasDevsSol, ...filasProductores],
      rutas: [RUTAS.pagos, RUTAS.devoluciones, RUTAS.productores],
    },
    {
      clave: 'hoy', etiqueta: 'Hoy', tono: 'info',
      descripcion: 'Entregas que deben prepararse, enviarse o recogerse',
      cantidad: entregasOperativas,
      filas: [...filasEntregas(entregasLista), ...filasEntregas(entregasCamino), ...filasEntregas(entregasPend)],
      rutas: [RUTAS.entregas],
    },
    {
      clave: 'proceso', etiqueta: 'En proceso', tono: 'info',
      descripcion: 'Pedidos en preparación o despachados, devoluciones aprobadas y compras por recibir',
      cantidad: pedidosPrepDesp + total(devsAprobadas) + comprasPorRecibir,
      filas: [...filasPedidos(pedidosPrep), ...filasPedidos(pedidosDesp), ...filasDevsApro,
        ...filasCompras(comprasRegistradas, 'Por recibir'), ...filasCompras(comprasParciales, 'Recepción parcial')],
      rutas: [RUTAS.pedidos, RUTAS.devoluciones, RUTAS.compras],
    },
    {
      clave: 'problemas', etiqueta: 'Problemas', tono: 'error',
      descripcion: 'Entregas que no pudieron completarse y necesitan seguimiento',
      cantidad: entregasProblemas,
      filas: [...filasEntregas(entregasFallidas), ...filasEntregas(entregasRetornadas), ...filasEntregas(entregasNoRecuperadas)],
      rutas: [RUTAS.entregas],
    },
    {
      clave: 'recientes', etiqueta: 'Recientes', tono: 'exito',
      descripcion: 'Pedidos y compras creados en los últimos 7 días que ya se completaron',
      cantidad: total(pedidosEntregados) + total(comprasRecibidas),
      filas: [...filasPedidos(pedidosEntregados), ...filasCompras(comprasRecibidas, 'Recibida')],
      rutas: [RUTAS.pedidos, RUTAS.compras],
    },
  ].filter((cola) => cola.filas.length > 0 || cola.cantidad > 0 || colaVisiblesPara(cola.clave, puede))

  const activa = colas.find((c) => c.clave === colaActiva) ?? colas[0]
  const filas = activa
    ? [...activa.filas].sort((a, b) => aInstante(b.fecha) - aInstante(a.fecha)).slice(0, 8)
    : []
  const enlaces = (activa?.rutas ?? []).filter((r) => puede[r.dominio])

  return (
    <PlantillaPanelOperativo
      titulo="Operaciones"
      descripcion="Pedidos, cobros y entregas que requieren seguimiento."
      cargando={cargando}
      error={error}
      recargar={() => consultas.forEach((c) => c.recargar())}
      colas={colas.map(({ filas: _omitido, rutas: _sinRutas, ...c }) => c)}
      colasActiva={activa?.clave}
      alSeleccionar={setColaActiva}
      vacio={!cargando && !error && colas.every((c) => c.cantidad === 0) ? {
        titulo: 'Todo al día',
        mensaje: 'No hay trabajo pendiente en ninguna cola. Vuelve más tarde o revisa los listados del menú.',
      } : null}
    >
      {!activa ? null : (
        <div className="operaciones">
          <div className="operaciones__cabecera">
            <h2 className="operaciones__titulo-cola">{activa.etiqueta}</h2>
            <div className="operaciones__enlaces">
              {enlaces.map((r) => (
                <Link key={r.ruta} to={r.ruta} className="boton boton--secundario">Abrir {TEXTO_RUTA[r.ruta]}</Link>
              ))}
            </div>
          </div>
          <div className="gestion__tabla-contenedor" role="region" aria-label={activa.etiqueta} tabIndex={0}>
            <table className="gestion__tabla">
              <thead><tr><th scope="col">Registro</th><th scope="col">Persona</th><th scope="col">Fecha</th><th scope="col">Estado</th><th scope="col">Monto</th></tr></thead>
              <tbody>
                {filas.map((f) => (
                  <tr key={`${f.fuente}-${f.id}`}>
                    <td><Link to={f.ruta}>{f.titulo}</Link></td>
                    <td>{f.persona}</td>
                    <td>{formatearFechaHora(f.fecha)}</td>
                    <td><Insignia tono={TONO_COLA[activa.clave] === 'error' ? 'error' : tonoEstado(f.estado)}>{f.etiquetaEstado}</Insignia></td>
                    <td>{f.monto != null ? formatearMoneda(f.monto) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {filas.length === 0 && <EstadoVacio titulo="Sin registros en esta cola" mensaje="Cuando los conteos cambien, verás aquí las filas más recientes." />}
        </div>
      )}
    </PlantillaPanelOperativo>
  )
}

const METODOS_TEXTO = { QR: 'QR', TARJETA: 'tarjeta', EFECTIVO: 'efectivo' }
const MODALIDAD_TEXTO = { RECOJO: 'en almacén', DELIVERY: 'a domicilio' }
const TONO_COLA = { atencion: 'aviso', hoy: 'info', proceso: 'info', problemas: 'error', recientes: 'exito' }
const TEXTO_RUTA = {
  '/gestion/pagos': 'pagos',
  '/gestion/devoluciones': 'devoluciones',
  '/gestion/productores': 'productores',
  '/gestion/entregas': 'entregas',
  '/gestion/pedidos': 'pedidos',
  '/gestion/compras': 'compras',
}

/** Una cola se muestra siempre que su dominio tenga permiso, aunque hoy esté vacía. */
function colaVisiblesPara(clave, puede) {
  if (clave === 'atencion') return puede.pagos || puede.devoluciones || puede.productores
  if (clave === 'hoy' || clave === 'problemas') return puede.entregas
  if (clave === 'proceso') return puede.pedidos || puede.devoluciones || puede.compras
  if (clave === 'recientes') return puede.pedidos || puede.compras
  return false
}
