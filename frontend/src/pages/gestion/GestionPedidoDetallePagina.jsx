import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { gestionApi } from '../../api/gestionApi'
import AnulacionFactura from '../../components/comercio/AnulacionFactura'
import DetallePedido from '../../components/comercio/DetallePedido'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import DialogoConfirmacion from '../../components/ui/DialogoConfirmacion'
import Cargando from '../../components/ui/Cargando'
import Insignia from '../../components/ui/Insignia'
import MensajeCampo from '../../components/ui/MensajeCampo'
import { useSesion } from '../../hooks/useSesion'
import { useCargaApi } from '../../hooks/useCargaApi'
import {
  avisoFaltaStock, avisoReintegro, cuerpoAvance, esConflicto, mensajeConflicto, resultadoCancelacion, textosAbastecer,
} from '../../utils/avancePedido'
import { ESTADOS_PEDIDO, TIPOS_PEDIDO, tonoEstado } from '../../utils/etiquetas'
import { formatearFechaHora, formatearMoneda } from '../../utils/formato'
import { atributosError, erroresDeApi } from '../../utils/validaciones'
import NoEncontradoPagina from '../estado/NoEncontradoPagina'
import './GestionPedidos.css'

const TEXTO_AVANZAR = {
  EN_PREPARACION: 'Empezar a preparar',
  DESPACHADO: 'Despachar pedido',
  ENTREGADO: 'Marcar como entregado',
}

export default function GestionPedidoDetallePagina() {
  const { id } = useParams()
  const { tienePermiso } = useSesion()
  const pedido = useCargaApi(() => gestionApi.pedido(id), [id])
  const [enviando, setEnviando] = useState(null)
  const [mensaje, setMensaje] = useState(null)
  const [motivo, setMotivo] = useState('')
  const [decision, setDecision] = useState(null)
  const [responsable, setResponsable] = useState('')
  const [telefonoResponsable, setTelefonoResponsable] = useState('')
  const [efectivoConfirmado, setEfectivoConfirmado] = useState(false)
  const [errores, setErrores] = useState({})

  if (pedido.cargando && !pedido.datos) return <Cargando texto="Cargando pedido…" />
  if (!pedido.datos) return <NoEncontradoPagina />

  const p = pedido.datos

  // exito: texto fijo o función que recibe el pedido actualizado (para mostrar el resultado real).
  const ejecutar = async (accion, llamada, exito) => {
    setEnviando(accion)
    setMensaje(null)
    setErrores({})
    try {
      const actualizado = await llamada()
      pedido.reemplazar(actualizado)
      setMensaje({ tipo: 'exito', texto: typeof exito === 'function' ? exito(actualizado) : exito })
      setMotivo('')
    } catch (falla) {
      if (esConflicto(falla)) {
        // La pantalla estaba desactualizada (otra persona movió el pedido): se vuelve a cargar y no se reintenta.
        setMensaje({ tipo: 'aviso', texto: mensajeConflicto(falla) })
        pedido.recargar()
        return
      }
      setErrores(erroresDeApi(falla))
      setMensaje({ tipo: 'error', texto: falla.mensaje })
    } finally {
      setEnviando(null)
    }
  }
  const marcarError = (campo, texto, idControl) => {
    setErrores({ [campo]: texto })
    document.getElementById(idControl)?.focus()
  }

  // Cancelar un pedido con pagos confirmados registra un reintegro por lo cobrado; el monto lo calcula el backend.
  const reintegroPrevisto = Number(p.acciones.reintegro_al_cancelar ?? 0)
  const textoReintegro = avisoReintegro(p.acciones)
  const textoFaltaStock = avisoFaltaStock(p.acciones)
  const abastecimiento = textosAbastecer(p)
  const cancelar = (e) => {
    e.preventDefault()
    if (motivo.trim().length < 3) return marcarError('motivo', 'Escribe el motivo de la cancelación (al menos 3 caracteres).', 'motivo')
    setDecision({
      titulo: 'Cancelar pedido', accion: 'Cancelar pedido', peligro: true,
      mensaje: `Se cancelará el pedido ${p.codigo}. Motivo: ${motivo.trim()}. ${textoReintegro || ''}`,
      alConfirmar: () => ejecutar('cancelar', () => gestionApi.cancelar(p.id, motivo.trim()), (actualizado) => resultadoCancelacion(actualizado, reintegroPrevisto)),
    })
  }
  const requiereResponsable = p.acciones.avanzar === 'DESPACHADO' && p.entrega?.modalidad === 'DELIVERY'
  const efectivoPendiente = p.pagos.find((pago) => pago.metodo === 'EFECTIVO' && pago.estado === 'PENDIENTE')
  const requiereEfectivo = p.acciones.avanzar === 'ENTREGADO' && Boolean(efectivoPendiente)
  const avanzar = () => {
    if (requiereResponsable && responsable.trim().length < 3) {
      marcarError('responsable_entrega', 'Indica quién llevará el pedido (al menos 3 caracteres).', 'responsable-entrega')
      return
    }
    if (requiereEfectivo && !efectivoConfirmado) {
      marcarError('efectivo_confirmado', 'Confirma que se recibió el efectivo antes de marcar la entrega.', 'efectivo-confirmado')
      return
    }
    const hacia = p.acciones.avanzar
    const datos = cuerpoAvance(hacia, { responsable, telefono: telefonoResponsable, efectivoConfirmado })
    setDecision({
      titulo: TEXTO_AVANZAR[hacia], accion: 'Confirmar avance',
      mensaje: `El pedido ${p.codigo} pasará a ${(ESTADOS_PEDIDO[hacia] ?? hacia).toLowerCase()}.${requiereEfectivo ? ' Se registrará el efectivo recibido.' : ''}`,
      alConfirmar: () => ejecutar('avanzar', () => gestionApi.avanzar(p.id, datos), (actualizado) =>
        `Pedido: ${(ESTADOS_PEDIDO[actualizado.estado] ?? actualizado.estado).toLowerCase()}.`),
    })
  }

  return (
    <div className="gestion">
      <Link to="/gestion/pedidos" className="gestion__volver"><ArrowLeft size={16} aria-hidden="true" /> Volver a pedidos</Link>

      <header className="gestion__cabecera">
        <div>
          <h1>{p.codigo}</h1>
          <p>{TIPOS_PEDIDO[p.tipo]} · {formatearFechaHora(p.fecha)} · {p.cliente.nombre} ({p.cliente.correo}, {p.cliente.telefono})</p>
        </div>
        <Insignia tono={tonoEstado(p.estado)}>{ESTADOS_PEDIDO[p.estado]}</Insignia>
      </header>

      {decision && <DialogoConfirmacion {...decision} alCerrar={() => setDecision(null)} />}
      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}
      {p.acciones.pago_por_conciliar && (
        <Alerta tipo="aviso">
          Hay un pago pendiente de verificación. Antes de cancelar este pedido, FEDER debe conciliar el pago.
          {tienePermiso('pagos.gestionar') && <> <Link to="/gestion/pagos">Ir a gestión de pagos</Link></>}
        </Alerta>
      )}
      {textoFaltaStock && <Alerta tipo="aviso">{textoFaltaStock}</Alerta>}

      <section className="gestion__acciones" aria-label="Acciones">
        {requiereResponsable && (
          <div className="gestion__accion-entrega">
            <label htmlFor="responsable-entrega">Quién llevará el pedido</label>
            <input id="responsable-entrega" aria-required="true" maxLength={120} value={responsable}
              onChange={(e) => { setResponsable(e.target.value); setErrores((a) => ({ ...a, responsable_entrega: null })) }}
              {...atributosError(errores, 'responsable_entrega', 'responsable-entrega-error')} />
            <MensajeCampo id="responsable-entrega-error" error={errores.responsable_entrega} />
            <label htmlFor="telefono-entrega">Teléfono del responsable</label>
            <input id="telefono-entrega" type="tel" maxLength={20} value={telefonoResponsable}
              onChange={(e) => { setTelefonoResponsable(e.target.value); setErrores((a) => ({ ...a, telefono_responsable: null })) }}
              {...atributosError(errores, 'telefono_responsable', 'telefono-entrega-error')} />
            <MensajeCampo id="telefono-entrega-error" error={errores.telefono_responsable} />
          </div>
        )}
        {requiereEfectivo && (
          <div>
            <label className="gestion__confirmacion">
              <input id="efectivo-confirmado" type="checkbox" checked={efectivoConfirmado}
                onChange={(e) => { setEfectivoConfirmado(e.target.checked); setErrores((a) => ({ ...a, efectivo_confirmado: null })) }}
                {...atributosError(errores, 'efectivo_confirmado', 'efectivo-confirmado-error')} />
              {' '}Confirmo que FEDER recibió {formatearMoneda(efectivoPendiente.monto)} en efectivo
            </label>
            <MensajeCampo id="efectivo-confirmado-error" error={errores.efectivo_confirmado} />
          </div>
        )}
        {p.acciones.avanzar && (
          <Boton cargando={enviando === 'avanzar'}
            onClick={avanzar}>
            {TEXTO_AVANZAR[p.acciones.avanzar]}
          </Boton>
        )}
        {p.acciones.abastecer && (
          <Boton cargando={enviando === 'abastecer'}
            onClick={() => setDecision({
              titulo: abastecimiento.boton, accion: 'Confirmar abastecimiento',
              mensaje: `Se verificará la disponibilidad de todas las líneas del encargo ${p.codigo} para reservar sus existencias.`,
              alConfirmar: () => ejecutar('abastecer', () => gestionApi.abastecer(p.id), abastecimiento.exito),
            })}>
            {abastecimiento.boton}
          </Boton>
        )}
        {p.acciones.cancelar && !p.acciones.pago_por_conciliar && (
          <form className="gestion__cancelar" onSubmit={cancelar} noValidate>
            <label htmlFor="motivo">Motivo de cancelación</label>
            <input id="motivo" aria-required="true" value={motivo} maxLength={200}
              onChange={(e) => { setMotivo(e.target.value); setErrores((a) => ({ ...a, motivo: null })) }}
              {...atributosError(errores, 'motivo', 'motivo-error')} />
            <MensajeCampo id="motivo-error" error={errores.motivo} />
            {textoReintegro && <p className="gestion__resumen" id="aviso-reintegro">{textoReintegro}</p>}
            <Boton tipo="submit" variante="peligro" cargando={enviando === 'cancelar'}>Cancelar pedido</Boton>
          </form>
        )}
        {!p.acciones.avanzar && !p.acciones.abastecer && !p.acciones.cancelar && (
          <p className="gestion__resumen">Este pedido no tiene acciones pendientes.</p>
        )}
      </section>

      <DetallePedido pedido={p} />
      {p.factura?.estado === 'VIGENTE' && tienePermiso('facturas.anular') && (
        <AnulacionFactura factura={p.factura} alRegistrar={pedido.recargar} />
      )}
    </div>
  )
}
