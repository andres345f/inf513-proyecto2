import { useState } from 'react'
import { facturasApi } from '../../api/facturasApi'
import Alerta from '../ui/Alerta'
import Boton from '../ui/Boton'

export default function AnulacionFactura({ factura, alRegistrar }) {
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState('')

  const registrar = async (evento) => {
    evento.preventDefault()
    const datos = new FormData(evento.currentTarget)
    const fecha = datos.get('fecha')
    setGuardando(true)
    setError('')
    try {
      await facturasApi.anular(factura.id, {
        motivo: datos.get('motivo').trim(),
        referencia: datos.get('referencia').trim(),
        fecha: `${fecha.length === 16 ? fecha + ':00' : fecha}-04:00`,
      })
      await alRegistrar()
    } catch (falla) {
      setError(falla.mensaje)
    } finally {
      setGuardando(false)
    }
  }

  return (
    <details className="gestion__acciones">
      <summary>Registrar anulación de factura</summary>
      <form className="gestion__cancelar" onSubmit={registrar}>
        <p>Registra el sustento externo de la anulación de la factura {factura.numero}. Esta acción guarda el asiento en FEDER.</p>
        <label>Motivo<input name="motivo" required minLength={3} maxLength={500} /></label>
        <label>Referencia del sustento<input name="referencia" required maxLength={250} /></label>
        <label>Fecha y hora del sustento en Bolivia<input name="fecha" type="datetime-local" required step="1" /></label>
        {error && <Alerta tipo="error">{error}</Alerta>}
        <Boton tipo="submit" variante="peligro" cargando={guardando}>Registrar anulación documentada</Boton>
      </form>
    </details>
  )
}
