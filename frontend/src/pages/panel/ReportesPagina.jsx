import { useState } from 'react'
import { FileSpreadsheet } from 'lucide-react'
import { estadisticasApi } from '../../api/estadisticasApi'
import RangoFechas from '../../components/panel/RangoFechas'
import EncabezadoPagina from '../../components/plantillas/EncabezadoPagina'
import Alerta from '../../components/ui/Alerta'
import Boton from '../../components/ui/Boton'
import './ReportesPagina.css'

const REPORTES = [
  { tipo: 'ventas', titulo: 'Ventas cobradas', detalle: 'Cada pago confirmado con su pedido, cliente, medio de pago y monto (importes brutos: los reintegros no se restan aquí, sí en el panel).', usaFechas: true },
  { tipo: 'pedidos', titulo: 'Pedidos', detalle: 'Pedidos del período con su estado, tipo de entrega, total y monto pagado.', usaFechas: true },
  { tipo: 'productos', titulo: 'Productos y stock', detalle: 'Catálogo completo con precio, stock disponible y stock mínimo (al día de hoy).', usaFechas: false },
]

/** Reportes descargables en CSV para abrir en Excel (CU8). */
export default function ReportesPagina() {
  const [rango, setRango] = useState({ desde: '', hasta: '' })
  const [descargando, setDescargando] = useState(null)
  const [mensaje, setMensaje] = useState(null)

  const descargar = async (reporte) => {
    setDescargando(reporte.tipo)
    setMensaje(null)
    try {
      const { blob, nombre } = await estadisticasApi.reporte(reporte.tipo, reporte.usaFechas
        ? { desde: rango.desde || undefined, hasta: rango.hasta || undefined } : {})
      const url = URL.createObjectURL(blob)
      const enlace = document.createElement('a')
      enlace.href = url
      enlace.download = nombre
      enlace.click()
      URL.revokeObjectURL(url)
      setMensaje({ tipo: 'exito', texto: `Descargaste ${nombre}.` })
    } catch (falla) {
      setMensaje({ tipo: 'error', texto: falla.mensaje })
    } finally {
      setDescargando(null)
    }
  }

  return (
    <div className="reportes">
      <EncabezadoPagina
        titulo="Reportes"
        descripcion="Archivos CSV que se abren directamente en Excel. Sin fechas, se usa el mes actual."
        accion={<RangoFechas desde={rango.desde} hasta={rango.hasta} alCambiar={setRango} />}
      />

      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}

      <ul className="reportes__lista">
        {REPORTES.map((r) => (
          <li key={r.tipo} className="reportes__item">
            <FileSpreadsheet size={32} aria-hidden="true" />
            <div className="reportes__texto">
              <h2>{r.titulo}</h2>
              <p>{r.detalle}</p>
            </div>
            <Boton variante="secundario" cargando={descargando === r.tipo} onClick={() => descargar(r)}>Descargar</Boton>
          </li>
        ))}
      </ul>
    </div>
  )
}
