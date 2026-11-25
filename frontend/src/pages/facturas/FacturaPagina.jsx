import { useRef } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, Printer } from 'lucide-react'
import { facturasApi } from '../../api/facturasApi'
import MarcaFeder from '../../components/ui/MarcaFeder'
import LineasImporte from '../../components/comercio/LineasImporte'
import BotonImprimir from '../../components/ui/BotonImprimir'
import Cargando from '../../components/ui/Cargando'
import { useCargaApi } from '../../hooks/useCargaApi'
import { formatearFechaHora } from '../../utils/formato'
import NoEncontradoPagina from '../estado/NoEncontradoPagina'
import './Facturas.css'

/** Factura imprimible: "Imprimir" permite también guardarla como PDF desde el navegador. */
export default function FacturaPagina() {
  const { id } = useParams()
  const referenciaPapel = useRef(null)
  const factura = useCargaApi(() => facturasApi.detalle(id), [id])

  if (factura.cargando) return <Cargando texto="Cargando factura…" />
  if (!factura.datos) return <NoEncontradoPagina />

  const f = factura.datos

  return (
    <div className="factura-pagina">
      <div className="factura-pagina__acciones no-imprimir">
        <Link to="/mis-facturas"><ArrowLeft size={16} aria-hidden="true" /> Mis facturas</Link>
        <Link to={`/mis-pedidos/${f.pedido_id}`}>Estado de cuenta del pedido</Link>
        <BotonImprimir referenciaPapel={referenciaPapel}><Printer size={18} aria-hidden="true" /> Imprimir o guardar PDF</BotonImprimir>
      </div>

      <article className="factura" aria-label={`Factura ${f.numero}`}>
        <header className="factura__cabecera">
          <div className="factura__emisor">
            <MarcaFeder referenciaPapel={referenciaPapel} />
            <span>Fundación de la Pequeña Industria y Artesanía · Santa Cruz, Bolivia</span>
          </div>
          <div className="factura__numero">
            <h1>Factura {f.numero}</h1>
            <span>{formatearFechaHora(f.fecha)}</span>
            {f.estado === 'ANULADA' && <strong className="factura__anulada">ANULADA</strong>}
          </div>
        </header>

        <dl className="factura__cliente">
          <dt>Señor(es)</dt><dd>{f.razon_social}</dd>
          <dt>NIT / CI</dt><dd>{f.nit_ci}</dd>
          <dt>Pedido</dt><dd>{f.pedido}</dd>
          {f.fecha_anulacion && <><dt>Fecha de anulación</dt><dd>{formatearFechaHora(f.fecha_anulacion)}</dd></>}
        </dl>

        <LineasImporte documento={f} />

        <footer className="factura__pie">
          {f.codigo_autorizacion && <span>Código de autorización: {f.codigo_autorizacion}</span>}
          <span>Documento de demostración académica (INF513 · UAGRM). Sin validez fiscal.</span>
        </footer>
      </article>
    </div>
  )
}
