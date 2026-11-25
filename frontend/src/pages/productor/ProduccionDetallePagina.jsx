import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { productoresApi } from '../../api/productoresApi'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import DialogoConfirmacion from '../../components/ui/DialogoConfirmacion'
import Cargando from '../../components/ui/Cargando'
import Insignia from '../../components/ui/Insignia'
import PlantillaDetalle from '../../components/plantillas/PlantillaDetalle'
import { useCargaApi } from '../../hooks/useCargaApi'
import { useMensajeTemporal } from '../../hooks/useMensajeTemporal'
import { cantidadConUnidad, tonoEstado, UNIDADES } from '../../utils/etiquetas'
import { formatearFecha } from '../../utils/formato'
import { enfocarPrimerError, erroresDeApi, validar } from '../../utils/validaciones'
import NoEncontradoPagina from '../estado/NoEncontradoPagina'
import FormularioLote from './FormularioLote'
import FormularioProduccion from './FormularioProduccion'
import TablaLotes from './TablaLotes'
import { reglasLote, reglasProduccion } from './reglasProductor'
import './Productor.css'

const ETIQUETA_PRODUCCION = { PLANIFICADA: 'Planificada', EN_CURSO: 'En curso', TERMINADA: 'Terminada', CANCELADA: 'Cancelada' }
const produccionVacia = { producto_id: '', fecha_inicio: '', fecha_disponibilidad_estimada: '', cantidad_estimada: '', ubicacion_produccion: '', observacion: '' }
const loteVacio = { fecha_elaboracion: '', fecha_vencimiento: '', cantidad_producida: '', cantidad_ofrecida: '', precio_ofrecido: '' }

/* Detalle de una producción propia con su gestión de lotes: acciones por estado,
   edición, alta/edición de lotes y retiro de ofertas. Ruta: /productor/producciones/:id */
export default function ProduccionDetallePagina() {
  const { id } = useParams()
  const detalle = useCargaApi(() => productoresApi.detalleProduccion(id), [id])
  const [editando, setEditando] = useState(false)
  const [formulario, setFormulario] = useState(produccionVacia)
  const [lote, setLote] = useState(loteVacio)
  const [editandoLote, setEditandoLote] = useState(null)
  const [comprometidoLote, setComprometidoLote] = useState(0)
  const [mensaje, setMensaje] = useState('')
  const [error, setError] = useState('')
  const [errores, setErrores] = useState({})
  const [ocupado, setOcupado] = useState(false)
  const [decision, setDecision] = useState(null)

  useMensajeTemporal(mensaje, setMensaje, 4500, Boolean(mensaje))

  if (detalle.cargando && !detalle.datos) return <Cargando texto="Cargando producción…" />
  if (!detalle.datos) return <NoEncontradoPagina mensaje={detalle.error ?? undefined} />

  const p = detalle.datos
  const porUnidad = p.unidad_medida === 'UNIDAD'
  const loteEnEdicion = p.lotes.find((l) => l.id === editandoLote)

  const ejecutar = async (accion, despues, formularioHtml = null) => {
    setOcupado(true)
    setError('')
    try {
      const r = await accion()
      setMensaje(r.message)
      await despues?.()
    } catch (falla) {
      setError(falla.mensaje)
      if (formularioHtml) {
        setErrores(erroresDeApi(falla))
        enfocarPrimerError(formularioHtml)
      }
    } finally {
      setOcupado(false)
    }
  }
  // Validación propia en español antes de llamar a la API (los formularios usan noValidate).
  const validarAntes = (evento, valores, reglas) => {
    evento.preventDefault()
    const erroresCliente = validar(valores, reglas)
    setErrores(erroresCliente)
    if (Object.keys(erroresCliente).length === 0) return true
    setError('Revisa los campos marcados.')
    enfocarPrimerError(evento.currentTarget)
    return false
  }
  const corregir = (campo) => setErrores((actual) => ({ ...actual, [campo]: null }))
  const cerrarEdicion = () => {
    setEditando(false)
    setErrores({})
    setFormulario(produccionVacia)
  }
  const abrirEdicion = () => {
    setEditando(true)
    setErrores({})
    setFormulario({
      producto_id: String(p.producto_id),
      fecha_inicio: p.fecha_inicio || '',
      fecha_disponibilidad_estimada: p.fecha_disponibilidad_estimada || '',
      cantidad_estimada: p.cantidad_estimada ?? '',
      ubicacion_produccion: p.ubicacion_produccion || '',
      observacion: p.observacion || '',
    })
  }
  const guardarProduccion = (evento) => {
    const formularioHtml = evento.currentTarget
    if (!validarAntes(evento, formulario, reglasProduccion(porUnidad))) return
    ejecutar(() => productoresApi.actualizarProduccion(p.id, formulario), async () => {
      cerrarEdicion()
      detalle.reemplazar(await productoresApi.detalleProduccion(p.id))
    }, formularioHtml)
  }
  const guardarLote = (evento) => {
    const formularioHtml = evento.currentTarget
    if (!validarAntes(evento, lote, reglasLote(porUnidad, comprometidoLote))) return
    // «nuevo» crea con POST en la producción; un ID numérico edita con PUT. Nunca /lotes/nuevo.
    ejecutar(() => (editandoLote === 'nuevo'
      ? productoresApi.crearLote(p.id, lote)
      : productoresApi.actualizarLote(p.id, editandoLote, lote)),
    async () => {
      setLote(loteVacio)
      setEditandoLote(null)
      setComprometidoLote(0)
      setErrores({})
      detalle.reemplazar(await productoresApi.detalleProduccion(p.id))
    }, formularioHtml)
  }
  const editarOferta = (l) => {
    setEditandoLote(l.id)
    setErrores({})
    setComprometidoLote(l.comprometido)
    setLote({
      fecha_elaboracion: l.fecha_elaboracion || '',
      fecha_vencimiento: l.fecha_vencimiento || '',
      cantidad_producida: l.cantidad_producida,
      cantidad_ofrecida: l.cantidad_ofrecida,
      precio_ofrecido: l.precio_ofrecido ?? '',
    })
  }
  const cambiarEstado = (estado) =>
    ejecutar(() => productoresApi.cambiarEstadoProduccion(p.id, estado), async () => {
      detalle.reemplazar(await productoresApi.detalleProduccion(p.id))
    })

  const cargado = Boolean(p)

  return (
    <PlantillaDetalle
      migas={[{ etiqueta: 'Mis producciones', ruta: '/productor/producciones' }]}
      titulo={cargado ? `${p.codigo} · ${p.producto}` : ''}
      descripcion={p?.ubicacion_produccion}
      estado={<Insignia tono={tonoEstado(p.estado) ?? 'info'}>{ETIQUETA_PRODUCCION[p.estado] ?? p.estado}</Insignia>}
      acciones={(
        <div className="productor__acciones">
          {p.puede_editar && <Boton variante="secundario" onClick={abrirEdicion} disabled={ocupado}>Editar producción</Boton>}
          {p.estado === 'PLANIFICADA' && <Boton onClick={() => cambiarEstado('EN_CURSO')} cargando={ocupado}>Iniciar producción</Boton>}
          {p.estado === 'EN_CURSO' && <Boton onClick={() => setDecision({
            titulo: 'Marcar producción terminada', accion: 'Marcar terminada',
            mensaje: `La producción ${p.codigo} de ${p.producto} quedará terminada.`,
            alConfirmar: () => cambiarEstado('TERMINADA'),
          })} cargando={ocupado}>Marcar terminada</Boton>}
          {p.puede_cancelar && <Boton variante="peligro" onClick={() => setDecision({
            titulo: 'Cancelar producción', accion: 'Cancelar producción', peligro: true,
            mensaje: `Se cancelará la producción ${p.codigo} de ${p.producto}.`,
            alConfirmar: () => cambiarEstado('CANCELADA'),
          })} cargando={ocupado}>Cancelar producción</Boton>}
        </div>
      )}
      resumen={(
        <>
          <dl className="productor__datos">
            <dt>Producto</dt><dd>{p.producto} · {p.presentacion}</dd>
            <dt>Unidad</dt><dd>{UNIDADES[p.unidad_medida]?.larga ?? p.unidad_medida}</dd>
            <dt>Comunidad</dt><dd>{p.comunidad || '—'}</dd>
            <dt>Inicio</dt><dd>{formatearFecha(p.fecha_inicio)}</dd>
            <dt>Disponibilidad estimada</dt><dd>{p.fecha_disponibilidad_estimada ? formatearFecha(p.fecha_disponibilidad_estimada) : '—'}</dd>
            <dt>Cantidad estimada</dt><dd>{p.cantidad_estimada != null ? cantidadConUnidad(p.cantidad_estimada, p.unidad_medida) : '—'}</dd>
          </dl>
          {p.observacion && <p className="productor__ayuda">{p.observacion}</p>}
        </>
      )}
    >
      {decision && <DialogoConfirmacion {...decision} alCerrar={() => setDecision(null)} />}
      {error && <Alerta tipo="error">{error}</Alerta>}
      {mensaje && <Alerta tipo="exito">{mensaje}</Alerta>}

      {editando && (
        <section className="productor__panel" aria-label="Editar producción">
          <h2>Editar producción</h2>
          <form className="productor__formulario" onSubmit={guardarProduccion} noValidate>
            <FormularioProduccion
              valores={formulario}
              alCambiar={setFormulario}
              errores={errores}
              alCorregir={corregir}
              opciones={[]}
              detalle={p}
              idDetalle="editar-produccion"
            />
            <div className="productor__acciones">
              <Boton tipo="submit" cargando={ocupado}>Guardar cambios</Boton>
              <Boton variante="fantasma" onClick={cerrarEdicion} disabled={ocupado}>Cancelar</Boton>
            </div>
          </form>
        </section>
      )}

      <section aria-label="Lotes de la producción">
        <div className="productor__fila-acciones">
          <h2>Lotes y ofertas</h2>
          {p.puede_registrar_lotes && !editandoLote && (
            <Boton variante="secundario" onClick={() => { setLote(loteVacio); setErrores({}); setEditandoLote('nuevo') }} disabled={ocupado}>Agregar lote</Boton>
          )}
        </div>

        {(editandoLote) && (
          <form className="productor__formulario" onSubmit={guardarLote} noValidate>
            <h3>{editandoLote === 'nuevo' ? 'Agregar lote y oferta' : 'Editar lote'}</h3>
            <FormularioLote
              valores={lote}
              alCambiar={setLote}
              errores={errores}
              alCorregir={corregir}
              unidad={p.unidad_medida}
              porUnidad={porUnidad}
              comprometido={comprometidoLote}
              origenBloqueado={loteEnEdicion?.puede_editar_datos_de_origen === false || loteEnEdicion?.tiene_compras === true}
              id="lotes-detalle"
            />
            <div className="productor__acciones">
              <Boton tipo="submit" cargando={ocupado}>{editandoLote === 'nuevo' ? 'Agregar lote' : 'Guardar lote'}</Boton>
              <Boton variante="fantasma" onClick={() => { setEditandoLote(null); setComprometidoLote(0); setLote(loteVacio); setErrores({}) }} disabled={ocupado}>Cancelar</Boton>
            </div>
          </form>
        )}

        <TablaLotes
          lotes={p.lotes}
          unidad={p.unidad_medida}
          puedeGestionar={p.estado !== 'CANCELADA'}
          alEditar={(l) => editarOferta(l)}
          alRetirar={(l) => setDecision({
            titulo: 'Retirar oferta', accion: 'Retirar oferta', peligro: true,
            mensaje: `Se retirará la oferta del lote ${l.codigo_lote} de esta producción.`,
            alConfirmar: () => ejecutar(() => productoresApi.retirarLote(l.id), async () => {
              detalle.reemplazar(await productoresApi.detalleProduccion(p.id))
            }),
          })}
          ocupado={ocupado}
        />
      </section>
    </PlantillaDetalle>
  )
}
