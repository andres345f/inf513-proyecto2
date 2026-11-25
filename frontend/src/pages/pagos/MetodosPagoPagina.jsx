import { useState } from 'react'

import { CreditCard, Star, Trash2 } from 'lucide-react'

import { comercioApi } from '../../api/comercioApi'

import FormularioNuevaTarjeta from '../../components/cliente/FormularioNuevaTarjeta'

import Alerta from '../../components/ui/Alerta'

import Boton from '../../components/ui/Boton'

import Insignia from '../../components/ui/Insignia'

import Tarjeta from '../../components/ui/Tarjeta'

import PaginaListado from '../../components/plantillas/PaginaListado'

import TarjetaItem from '../../components/plantillas/TarjetaItem'

import { useCargaApi } from '../../hooks/useCargaApi'

import './MetodosPagoPagina.css'

/**
 * /metodos-pago (permiso metodos_pago.gestionar): página propia de tarjetas.
 * La misma carga de tarjeta se abre como modal desde Mi cuenta y el checkout
 * (components/cliente/ModalMetodoPago) sin duplicar el formulario.
 */
export default function MetodosPagoPagina() {
  const metodos = useCargaApi(() => comercioApi.metodosPago(), [])
  const [mensaje, setMensaje] = useState(null)
  const [ocupado, setOcupado] = useState(null)

  const ejecutar = async (id, accion, texto) => {
    setOcupado(id); setMensaje(null)
    try { const r = await accion(); if (r.metodos) metodos.reemplazar(r); else metodos.recargar(); setMensaje({ tipo: 'exito', texto }) }
    catch (falla) { setMensaje({ tipo: 'error', texto: falla.mensaje }) }
    finally { setOcupado(null) }
  }

  return (
    <PaginaListado
        titulo="Métodos de pago"
        descripcion="Administra las tarjetas que deseas usar para tus compras."
        cargando={metodos.cargando} actualizando={metodos.actualizando}>
      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}
      {metodos.error && <Alerta tipo="error">{metodos.error}</Alerta>}

      <div className="metodos-pago__rejilla">
        <Tarjeta titulo="Tarjetas guardadas">
          {metodos.datos?.metodos.length ? (
            <div className="pagina-listado__lista">
              {metodos.datos.metodos.map((m) => (
                <TarjetaItem
                  key={m.id}
                  icono={CreditCard}
                  titulo={`${m.marca} terminada en ${m.ultimos4}`}
                  subtitulo={`${m.alias || m.titular} · vence ${m.expira}`}
                  insignia={
                    <>
                      {m.predeterminado && <Insignia tono="exito">Predeterminada</Insignia>}
                      {m.vencida && <Insignia tono="error">Vencida</Insignia>}
                    </>
                  }
                  acciones={
                    <>
                      {!m.predeterminado && !m.vencida && (
                        <Boton variante="secundario" disabled={ocupado === m.id}
                          onClick={() => ejecutar(m.id, () => comercioApi.metodoPredeterminado(m.id), 'Marcamos la tarjeta como predeterminada.')}>
                          <Star size={16} aria-hidden="true" /> Usar primero
                        </Boton>
                      )}
                      <Boton variante="fantasma" disabled={ocupado === m.id}
                        onClick={() => ejecutar(m.id, () => comercioApi.eliminarMetodoPago(m.id), 'Eliminamos la tarjeta de tus métodos de pago.')}>
                        <Trash2 size={16} aria-hidden="true" /> Eliminar
                      </Boton>
                    </>
                  }
                />
              ))}
            </div>
          ) : (
            <p className="pagina-listado__resumen">Todavía no guardaste ninguna tarjeta.</p>
          )}
        </Tarjeta>

        <Tarjeta titulo="Agregar tarjeta">
          <FormularioNuevaTarjeta alGuardado={(texto) => { metodos.recargar(); setMensaje({ tipo: 'exito', texto }) }} />
        </Tarjeta>
      </div>
    </PaginaListado>
  )
}
